<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\SignatureDelta;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ThinkingBlock;
use Anthropic\Messages\ThinkingDelta;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\RequestOptions;
use Psr\Http\Client\ClientInterface;

/**
 * Claude über das offizielle PHP-SDK von Anthropic.
 */
final class AnthropicChat implements ChatModel
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly ?string $baseUrl = null,
        /** Nur für Tests: ein eigener HTTP-Client statt des gefundenen. */
        private readonly ?ClientInterface $transport = null,
    ) {
    }

    public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
    {
        $parameter = [
            'model'     => $this->model,
            'maxTokens' => 16000,
            'system'    => $system,
            // Ohne Werkzeuge bleibt das Feld weg.
            'tools'     => $tools === [] ? null : \array_map(static fn (array $t) => [
                'name'        => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['schema'],
            ], $tools),
            'messages'  => $this->toWire($messages),
            // Aufgaben und Zeiten aus einem Satz abzuleiten ist keine schwere
            // Aufgabe; der niedrige Aufwand hält die Wartezeit kurz.
            'outputConfig' => ['effort' => 'low'],
        ];

        try {
            $inhalt = $beiText === null
                ? $this->client()->messages->create(...$parameter)->content
                : $this->streamen($parameter, $beiText);
        } catch (APIStatusException $e) {
            throw new AssistantUnavailable(match ($e->type?->value) {
                'authentication_error' => 'Der hinterlegte Schlüssel wird von Anthropic nicht akzeptiert.',
                'not_found_error'      => \sprintf('Anthropic kennt das Modell „%s" nicht.', $this->model),
                'rate_limit_error'     => 'Zu viele Anfragen in kurzer Zeit. Bitte kurz warten.',
                'overloaded_error'     => 'Anthropic ist gerade überlastet. Bitte gleich noch einmal versuchen.',
                default                => 'Anthropic hat die Anfrage abgelehnt: '.$e->getMessage(),
            }, previous: $e);
        } catch (\Throwable $e) {
            throw new AssistantUnavailable('Anthropic ist nicht erreichbar: '.$e->getMessage(), previous: $e);
        }

        $texte = [];
        $rufe  = [];

        foreach ($inhalt as $block) {
            if ($block->type === 'text') {
                $texte[] = $block->text;
            } elseif ($block instanceof ToolUseBlock) {
                $rufe[] = [
                    'id'    => $block->id,
                    'name'  => $block->name,
                    'input' => $block->input,
                ];
            }
        }

        // Die Blöcke wandern unverändert zurück in den Verlauf: Denkblöcke
        // tragen eine Signatur, die beim Fortsetzen erhalten bleiben muss.
        return new ModelTurn(\trim(\implode("\n\n", $texte)), $rufe, $inhalt);
    }

    /**
     * Holt die Antwort als Strom und setzt daraus dieselben Blöcke zusammen,
     * die create() geliefert hätte – samt Signaturen der Denkblöcke, die beim
     * Fortsetzen unverändert zurückmüssen.
     *
     * @param array<string, mixed>   $parameter
     * @param \Closure(string): void $beiText
     *
     * @return list<object>
     */
    private function streamen(array $parameter, \Closure $beiText): array
    {
        $bloecke  = [];
        $text     = [];
        $json     = [];
        $denken   = [];
        $signatur = [];

        foreach ($this->client()->messages->createStream(...$parameter) as $ereignis) {
            if ($ereignis instanceof RawContentBlockStartEvent) {
                $bloecke[$ereignis->index] = $ereignis->contentBlock;

                continue;
            }

            if (!$ereignis instanceof RawContentBlockDeltaEvent) {
                continue;
            }

            $i     = $ereignis->index;
            $stueck = $ereignis->delta;

            if ($stueck instanceof TextDelta) {
                $text[$i] = ($text[$i] ?? '').$stueck->text;
                $beiText($stueck->text);
            } elseif ($stueck instanceof InputJSONDelta) {
                $json[$i] = ($json[$i] ?? '').$stueck->partialJSON;
            } elseif ($stueck instanceof ThinkingDelta) {
                $denken[$i] = ($denken[$i] ?? '').$stueck->thinking;
            } elseif ($stueck instanceof SignatureDelta) {
                $signatur[$i] = ($signatur[$i] ?? '').$stueck->signature;
            }
        }

        \ksort($bloecke);
        $fertig = [];

        foreach ($bloecke as $i => $block) {
            $fertig[] = match (true) {
                $block instanceof TextBlock     => TextBlock::with($block->citations, $block->text.($text[$i] ?? '')),
                $block instanceof ThinkingBlock => ThinkingBlock::with($block->signature.($signatur[$i] ?? ''), $block->thinking.($denken[$i] ?? '')),
                $block instanceof ToolUseBlock  => $block->withInput(self::eingabe($json[$i] ?? '')),
                default                         => $block,
            };
        }

        return $fertig;
    }

    /** @return array<string, mixed> */
    private static function eingabe(string $json): array
    {
        $daten = \json_decode($json === '' ? '{}' : $json, true);

        return \is_array($daten) ? $daten : [];
    }

    /**
     * Übersetzt den neutralen Verlauf in das Format der Messages-API.
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
                $wire[] = ['role' => 'user', 'content' => \array_map(static fn (array $r) => [
                    'type'      => 'tool_result',
                    'toolUseID' => $r['id'],
                    'content'   => $r['text'],
                ], $nachricht['results'])];

                continue;
            }

            if ($nachricht['role'] === 'assistant') {
                // Liegt die Originalantwort vor, hat sie Vorrang – nur so
                // bleiben Signaturen und Denkblöcke unverändert erhalten.
                if (($nachricht['raw'] ?? null) !== null) {
                    $wire[] = ['role' => 'assistant', 'content' => $nachricht['raw']];

                    continue;
                }

                $content = [];

                if (\trim((string) ($nachricht['text'] ?? '')) !== '') {
                    $content[] = ['type' => 'text', 'text' => $nachricht['text']];
                }

                foreach ($nachricht['toolCalls'] ?? [] as $ruf) {
                    $content[] = ['type' => 'tool_use', 'id' => $ruf['id'], 'name' => $ruf['name'], 'input' => $ruf['input']];
                }

                $wire[] = ['role' => 'assistant', 'content' => $content === [] ? ' ' : $content];

                continue;
            }

            $wire[] = ['role' => 'user', 'content' => $nachricht['text']];
        }

        return $wire;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            requestOptions: $this->transport === null ? null : RequestOptions::with(transporter: $this->transport, streamingTransporter: $this->transport),
        );
    }
}
