<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Ein Zug des Modells: der Antworttext und die Werkzeuge, die es aufrufen will.
 */
final readonly class ModelTurn
{
    /**
     * @param list<array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param mixed $raw Eigendarstellung des Anbieters; kommt unverändert zurück,
     *                   damit etwa Anthropics Denkblöcke unangetastet bleiben.
     */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
        public mixed $raw = null,
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
