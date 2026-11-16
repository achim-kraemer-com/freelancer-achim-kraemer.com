<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Service\Assistant\Ausgefuehrt;
use App\Service\Assistant\BadToolInput;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ein Bereich von Werkzeugen des Assistenten – Lesen, Kunden, Aufgaben,
 * Rechnungen.
 *
 * Der Werkzeugkasten sammelt alle Bereiche ein und reicht jeden Aufruf an den
 * weiter, der das Werkzeug beschreibt. Ein neuer Bereich ist damit eine neue
 * Klasse, die Werkzeugbasis erweitert; anderswo muss nichts nachgetragen
 * werden.
 *
 * Kunden, Projekte, Aufgaben und Rechnungen gehören hier allen Angemeldeten
 * gemeinsam; die Werkzeuge brauchen deshalb keinen Benutzer.
 *
 * @phpstan-type Plan array{ok: bool, message: string, action?: array{tool: string, summary: string, details: array<string, string>, input: array<string, mixed>}}
 */
#[AutoconfigureTag(self::TAG)]
interface Werkzeugbereich
{
    public const TAG = 'app.assistent.werkzeuge';

    /**
     * Die Werkzeugbeschreibungen – anbieterneutral.
     *
     * @return list<array{name: string, description: string, schema: array<string, mixed>}>
     */
    public function definitionen(): array;

    /**
     * Welche der Werkzeuge etwas verändern und deshalb erst bestätigt werden.
     *
     * @return list<string>
     */
    public function schreibende(): array;

    /** @param array<string, mixed> $input */
    public function lesen(string $tool, array $input): string;

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     *
     * @throws BadToolInput wenn die Eingabe nicht passt
     */
    public function planen(string $tool, array $input): array;

    /**
     * @param array<string, mixed> $input
     *
     * @throws BadToolInput wenn die Aktion nicht mehr ausführbar ist
     */
    public function uebernehmen(string $tool, array $input): Ausgefuehrt;
}
