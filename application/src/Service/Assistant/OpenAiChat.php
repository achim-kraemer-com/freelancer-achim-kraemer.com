<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Alle Anbieter, die die Chat-Completions-Schnittstelle von OpenAI sprechen:
 * OpenAI selbst, OpenRouter, Groq, Together, Google über seinen OpenAI-Endpunkt
 * und lokale Modelle über Ollama oder LM Studio. Der Unterschied ist allein die
 * Adresse – deshalb reicht eine einzige Umsetzung.
 *
 * Bewusst ohne SDK: die Schnittstelle ist an dieser Stelle klein, und der
 * HttpClient von Symfony liegt ohnehin im Projekt.
 */
final class OpenAiChat implements ChatModel
{
    /** Länger wartet niemand vor einem Eingabefeld. */
    private const TIMEOUT = 120;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        /** none, low, medium, high – oder null für die Vorgabe des Anbieters. */
        private readonly ?string $reasoningEffort = null,
    ) {
    }

    public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
    {
        $body = [
            'model'    => $this->model,
            'messages' => \array_merge([['role' => 'system', 'content' => $system]], $this->toWire($messages)),
        ];

        // Nur mitschicken, wenn ausdrücklich gewählt: OpenAI lehnt den Parameter
        // bei Modellen ohne Denkmodus ab, Ollama versteht "none" als "nicht denken".
        if ($this->reasoningEffort !== null) {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        if ($tools !== []) {
            $body['tools'] = \array_map(static fn (array $t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'],
                    'parameters'  => $t['schema'],
                ],
            ], $tools);
            $body['tool_choice'] = 'auto';
        }

        if ($beiText !== null) {
            $body['stream'] = true;
        }

        $kopfzeilen = ['Content-Type' => 'application/json'];

        // Lokale Modelle laufen oft ohne Schlüssel; dann bleibt der Kopf weg.
        if ($this->apiKey !== '') {
            $kopfzeilen['Authorization'] = 'Bearer '.$this->apiKey;
        }

        try {
            $response = $this->http->request('POST', $this->baseUrl.'/chat/completions', [
                'headers' => $kopfzeilen,
                'json'    => $body,
                'timeout' => self::TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            // Nicht jeder Anbieter streamt, auch wenn man ihn darum bittet –
            // dann kommt die Antwort wie gewohnt in einem Stück.
            if ($beiText !== null && $status < 400 && \str_contains($response->getHeaders(false)['content-type'][0] ?? '', 'text/event-stream')) {
                return $this->streamen($response, $beiText);
            }

            $daten = $response->toArray(throw: false);
        } catch (TransportException $e) {
            throw new AssistantUnavailable(\sprintf('%s ist nicht erreichbar: %s', $this->host(), $e->getMessage()), previous: $e);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new AssistantUnavailable(\sprintf('%s hat unverständlich geantwortet: %s', $this->host(), $e->getMessage()), previous: $e);
        }

        if ($status >= 400) {
            throw new AssistantUnavailable($this->explain($status, $daten));
        }

        $nachricht = $daten['choices'][0]['message'] ?? null;

        if (!\is_array($nachricht)) {
            throw new AssistantUnavailable(\sprintf('%s hat keine verwertbare Antwort geliefert.', $this->host()));
        }

        $text = \trim((string) ($nachricht['content'] ?? ''));

        if ($beiText !== null && $text !== '') {
            $beiText($text);
        }

        return new ModelTurn($text, $this->readToolCalls($nachricht));
    }

    /**
     * Liest die Antwort als Server-Sent Events: Textstücke gehen sofort an
     * $beiText, die Werkzeugaufrufe kommen in Teilen und werden je Index
     * zusammengesetzt – Name und Kennung im ersten Stück, die Argumente als
     * JSON in vielen.
     *
     * @param \Closure(string): void $beiText
     */
    private function streamen(ResponseInterface $response, \Closure $beiText): ModelTurn
    {
        $puffer = '';
        $text   = '';
        $rufe   = [];

        foreach ($this->http->stream($response, self::TIMEOUT) as $stueck) {
            if ($stueck->isTimeout()) {
                throw new AssistantUnavailable(\sprintf('%s antwortet nicht mehr.', $this->host()));
            }

            $puffer .= $stueck->getContent();

            while (($ende = \strpos($puffer, "\n")) !== false) {
                $zeile  = \trim(\substr($puffer, 0, $ende));
                $puffer = \substr($puffer, $ende + 1);

                if (!\str_starts_with($zeile, 'data:')) {
                    continue;
                }

                $nutzlast = \trim(\substr($zeile, 5));

                if ($nutzlast === '[DONE]') {
                    break 2;
                }

                $daten = \json_decode($nutzlast, true);
                $delta = \is_array($daten) ? ($daten['choices'][0]['delta'] ?? null) : null;

                if (!\is_array($delta)) {
                    continue;
                }

                if (\is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                    $text .= $delta['content'];
                    $beiText($delta['content']);
                }

                foreach ($delta['tool_calls'] ?? [] as $teil) {
                    $i = (int) ($teil['index'] ?? 0);
                    $rufe[$i] ??= ['id' => null, 'function' => ['name' => '', 'arguments' => '']];

                    if (isset($teil['id'])) {
                        $rufe[$i]['id'] = $teil['id'];
                    }

                    $rufe[$i]['function']['name']      .= (string) ($teil['function']['name'] ?? '');
                    $argumente = $teil['function']['arguments'] ?? '';

                    // Manche Anbieter schicken die Argumente als Objekt statt als Text.
                    $rufe[$i]['function']['arguments'] .= \is_array($argumente)
                        ? (\json_encode($argumente, \JSON_UNESCAPED_UNICODE) ?: '')
                        : (string) $argumente;
                }
            }
        }

        \ksort($rufe);

        return new ModelTurn(\trim($text), $this->readToolCalls(['tool_calls' => \array_values(\array_map(
            static fn (array $ruf) => $ruf['id'] === null ? ['function' => $ruf['function']] : $ruf,
            $rufe,
        ))]));
    }

    /**
     * @param array<string, mixed> $nachricht
     *
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    private function readToolCalls(array $nachricht): array
    {
        $rufe = [];

        foreach ($nachricht['tool_calls'] ?? [] as $index => $ruf) {
            $name = $ruf['function']['name'] ?? null;

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $roh = $ruf['function']['arguments'] ?? '{}';

            // Nach Vorgabe ist "arguments" eine JSON-Zeichenkette; einzelne
            // Anbieter schicken das Objekt direkt. Beides wird angenommen.
            if (\is_array($roh)) {
                $eingabe = $roh;
            } else {
                $eingabe = \json_decode((string) $roh, true);
                $eingabe = \is_array($eingabe) ? $eingabe : [];
            }

            $rufe[] = [
                // Ollama und andere lassen die Kennung manchmal weg.
                'id'    => (string) ($ruf['id'] ?? 'ruf_'.$index),
                'name'  => $name,
                'input' => $eingabe,
            ];
        }

        return $rufe;
    }

    /**
     * Übersetzt den neutralen Verlauf. Anders als bei Anthropic wird jedes
     * Werkzeugergebnis eine eigene Nachricht mit der Rolle "tool".
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function toWire(array $messages): array
    {
        $wire = [];

        foreach ($messages as $nachricht) {
            if ($nachricht['role'] === 'tool') {
                foreach ($nachricht['results'] as $ergebnis) {
                    $wire[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $ergebnis['id'],
                        'content'      => $ergebnis['text'],
                    ];
                }

                continue;
            }

            if ($nachricht['role'] === 'assistant') {
                $eintrag = ['role' => 'assistant', 'content' => (string) ($nachricht['text'] ?? '')];

                if (($nachricht['toolCalls'] ?? []) !== []) {
                    $eintrag['tool_calls'] = \array_map(static fn (array $ruf) => [
                        'id'       => $ruf['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $ruf['name'],
                            // Ein Aufruf ohne Angaben ist das leere Objekt "{}" – json_encode()
                            // machte aus dem leeren Array "[]", und das lehnen strenge
                            // Anbieter (Nvidia über OpenRouter) ab.
                            'arguments' => \json_encode($ruf['input'] === [] ? new \stdClass() : $ruf['input'], \JSON_UNESCAPED_UNICODE) ?: '{}',
                        ],
                    ], $nachricht['toolCalls']);
                }

                $wire[] = $eintrag;

                continue;
            }

            $wire[] = ['role' => 'user', 'content' => $nachricht['text']];
        }

        return $wire;
    }

    /** @param array<string, mixed> $daten */
    private function explain(int $status, array $daten): string
    {
        $meldung = $daten['error']['message'] ?? $daten['message'] ?? null;

        // OpenRouter reicht Fehler der dahinterliegenden Anbieter nur als
        // "Provider returned error" durch; die eigentliche Ursache steht in
        // metadata.raw, der Anbieter in metadata.provider_name. Beides anhängen,
        // sonst lässt sich in der Oberfläche nichts diagnostizieren.
        $meta     = $daten['error']['metadata'] ?? [];
        $anbieter = \is_array($meta) && \is_string($meta['provider_name'] ?? null) ? $meta['provider_name'] : null;
        $roh      = \is_array($meta) ? ($meta['raw'] ?? null) : null;

        if (\is_array($roh)) {
            $roh = \json_encode($roh, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null;
        }

        if (\is_string($roh) && $roh !== '') {
            $roh     = \mb_strimwidth(\trim($roh), 0, 300, '…');
            $meldung = ($meldung !== null ? $meldung.' – ' : '').($anbieter !== null ? $anbieter.': ' : '').$roh;
        } elseif ($anbieter !== null) {
            $meldung = ($meldung !== null ? $meldung.' – ' : '').$anbieter;
        }

        $grund = match (true) {
            $status === 401, $status === 403 => \sprintf('Der hinterlegte Schlüssel wird von %s nicht akzeptiert.', $this->host()),
            $status === 404                  => \sprintf('%s kennt weder das Modell „%s" noch diese Adresse.', $this->host(), $this->model),
            $status === 429                  => 'Zu viele Anfragen oder das Guthaben ist aufgebraucht. Bitte kurz warten.',
            $status >= 500                   => \sprintf('%s hat einen Fehler gemeldet (%d).', $this->host(), $status),
            default                          => \sprintf('%s hat die Anfrage abgelehnt (%d).', $this->host(), $status),
        };

        return \is_string($meldung) && $meldung !== '' ? $grund.' – '.$meldung : $grund;
    }

    private function host(): string
    {
        return \parse_url($this->baseUrl, \PHP_URL_HOST) ?: $this->baseUrl;
    }
}
