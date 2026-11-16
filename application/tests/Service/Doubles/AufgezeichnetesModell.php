<?php

declare(strict_types=1);

namespace App\Tests\Service\Doubles;

use App\Service\Assistant\ChatModel;
use App\Service\Assistant\ModelTurn;

/**
 * Ein Sprachmodell für Tests: Es liefert die vorgegebenen Züge der Reihe nach
 * und schreibt mit, was es dabei zu sehen bekommt – Systemprompt, Werkzeuge
 * und den Verlauf je Runde.
 */
final class AufgezeichnetesModell implements ChatModel
{
    public string $system = '';

    /** @var list<array{name: string, description: string, schema: array<string, mixed>}> */
    public array $tools = [];

    /** @var list<list<array<string, mixed>>> ein Eintrag je Runde */
    public array $verlauf = [];

    /** @param list<ModelTurn> $zuege */
    public function __construct(private array $zuege)
    {
    }

    public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
    {
        $this->system    = $system;
        $this->tools     = $tools;
        $this->verlauf[] = $messages;

        return \array_shift($this->zuege) ?? new ModelTurn('Nichts mehr zu sagen.');
    }

    /** Die Werkzeugergebnisse, die nach Runde $runde zurückgingen. */
    public function werkzeugErgebnisse(int $runde): string
    {
        $messages = $this->verlauf[$runde] ?? [];
        $letzte   = \end($messages);

        if (!\is_array($letzte) || ($letzte['role'] ?? null) !== 'tool') {
            return '';
        }

        return \implode("\n", \array_column($letzte['results'], 'text'));
    }
}
