<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use App\Entity\AssistantModel;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Baut aus einem gespeicherten Modell die passende Anbindung und entschlüsselt
 * dabei den Schlüssel. Der einzige Ort im Code, der die Anbieter auseinanderhält.
 */
final class ChatModelFactory
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly HttpClientInterface $http,
    ) {
    }

    /** @throws AssistantUnavailable wenn die Angaben nicht ausreichen */
    public function create(AssistantModel $config): ChatModel
    {
        $key   = (string) $this->cipher->decrypt($config->getApiKey());
        $model = (string) $config->getModel();

        if ($config->isAnthropic()) {
            if ($key === '') {
                throw new AssistantUnavailable(\sprintf(
                    'Für „%s" ist kein Schlüssel hinterlegt. Unter Modelle nachtragen.',
                    (string) $config->getLabel(),
                ));
            }

            return new AnthropicChat($key, $model, $config->resolveBaseUrl());
        }

        $url = $config->resolveBaseUrl();

        if ($url === null) {
            throw new AssistantUnavailable(\sprintf('Für „%s" fehlt die Adresse des Anbieters.', (string) $config->getLabel()));
        }

        // Ein Schlüssel ist hier bewusst nicht Pflicht: lokale Modelle über
        // Ollama oder LM Studio kommen ohne aus.
        return new OpenAiChat($this->http, $key, $model, $url, $config->getReasoningEffort());
    }
}
