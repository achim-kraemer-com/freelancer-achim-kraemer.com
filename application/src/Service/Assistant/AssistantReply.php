<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Was der Assistent auf eine Frage zurückgibt: den Antworttext und die
 * Änderungen, die er vorbereitet hat und die noch bestätigt werden müssen.
 */
final readonly class AssistantReply
{
    /**
     * @param list<array{tool: string, summary: string, details: array<string, string>, input: array<string, mixed>}> $actions
     */
    public function __construct(
        public string $text,
        public array $actions,
    ) {
    }
}
