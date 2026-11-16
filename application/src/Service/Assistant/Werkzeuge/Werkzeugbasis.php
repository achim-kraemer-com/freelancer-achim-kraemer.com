<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Service\Assistant\Ausgefuehrt;
use App\Service\Assistant\BadToolInput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Was alle Werkzeugbereiche brauchen: Links in die Verwaltung, das Vormerken
 * schreibender Aufrufe, das Prüfen von Datum, Zahl und Pflichtfeldern und die
 * Antwort als JSON.
 *
 * Ein Bereich ohne schreibende Werkzeuge muss planen() und uebernehmen()
 * nicht selbst umsetzen; hier weisen sie jeden Aufruf ab.
 *
 * @phpstan-import-type Plan from Werkzeugbereich
 */
abstract class Werkzeugbasis implements Werkzeugbereich
{
    /** So viel Text eines Eintrags bekommt das Modell höchstens zu lesen. */
    protected const MAX_TEXT = 4000;

    /** Mehr Treffer braucht das Modell nicht, um die richtige Zeile zu finden. */
    protected const MAX_TREFFER = 40;

    protected const DATUM = 'Datum im Format JJJJ-MM-TT.';

    public function __construct(protected readonly UrlGeneratorInterface $urls)
    {
    }

    public function schreibende(): array
    {
        return [];
    }

    public function lesen(string $tool, array $input): string
    {
        return $this->unbekannt($tool);
    }

    public function planen(string $tool, array $input): array
    {
        throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".');
    }

    public function uebernehmen(string $tool, array $input): Ausgefuehrt
    {
        throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".');
    }

    protected function unbekannt(string $tool): string
    {
        return 'Fehler: Unbekanntes Werkzeug "'.$tool.'".';
    }

    /** Wo ein Eintrag steht – als Pfad, den Modell und Seite gleich verwenden. */
    protected function link(string $route, ?int $id): string
    {
        return $this->urls->generate($route, ['id' => $id]);
    }

    /**
     * @param array<string, string> $details
     * @param array<string, mixed>  $input
     *
     * @return Plan
     */
    protected function planned(string $tool, string $summary, array $details, array $input): array
    {
        return [
            'ok'      => true,
            'message' => 'Vorgemerkt: '.$summary.'. Der Benutzer bestätigt gleich in der Oberfläche – nicht erneut aufrufen.',
            'action'  => [
                'tool'    => $tool,
                'summary' => $summary,
                'details' => $details,
                'input'   => $input,
            ],
        ];
    }

    protected function parseDate(string $wert, string $feld): \DateTimeImmutable
    {
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', \trim($wert));

        if ($datum === false) {
            throw new BadToolInput('"'.$feld.'" braucht ein Datum im Format JJJJ-MM-TT, angekommen ist "'.$wert.'".');
        }

        return $datum;
    }

    /** @param array<string, mixed> $input */
    protected function requireText(array $input, string $feld): string
    {
        $wert = \trim((string) ($input[$feld] ?? ''));

        if ($wert === '') {
            throw new BadToolInput('Das Feld "'.$feld.'" darf nicht leer sein.');
        }

        return $wert;
    }

    /**
     * Eine Zahl aus einem Werkzeugaufruf – das Modell schickt sie als Zahl
     * oder als Text, mit Punkt oder Komma („7,5" Stunden).
     *
     * @param array<string, mixed> $input
     */
    protected function zahl(array $input, string $feld): ?float
    {
        $wert = $input[$feld] ?? null;

        if ($wert === null || (\is_string($wert) && \trim($wert) === '')) {
            return null;
        }

        if (\is_int($wert) || \is_float($wert)) {
            return (float) $wert;
        }

        $text = \str_replace([' ', ','], ['', '.'], \trim((string) $wert));

        if (!\is_numeric($text)) {
            throw new BadToolInput('"'.$feld.'" braucht eine Zahl, angekommen ist "'.(string) $wert.'".');
        }

        $zahl = (float) $text;

        if ($zahl < 0) {
            throw new BadToolInput('"'.$feld.'" darf nicht negativ sein.');
        }

        return $zahl;
    }

    /** Ein Betrag, wie er in der Oberfläche steht: „1.234,50 €". */
    protected function geld(float $betrag): string
    {
        return \number_format($betrag, 2, ',', '.').' €';
    }

    /** Stunden mit zwei Stellen, ohne überflüssige Nullen davor: „7,50 Std.". */
    protected function stunden(float $stunden): string
    {
        return \number_format($stunden, 2, ',', '.').' Std.';
    }

    /** @param array<string, mixed> $daten */
    protected function encode(array $daten): string
    {
        return (string) \json_encode($daten, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
