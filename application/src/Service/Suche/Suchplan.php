<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * Was gesucht wird – das Ergebnis der Übersetzung einer Eingabe durch die KI
 * (siehe KiSuchplaner), oder ohne KI schlicht der Wortlaut.
 *
 * Ein Eintrag gilt als Treffer, wenn einer der Begriffe passt. Ein Begriff
 * passt, wenn alle seine Wörter vorkommen – irgendwo in den durchsuchten
 * Feldern, in beliebiger Reihenfolge. So findet „Müller Webshop" auch
 * „Webshop-Relaunch, Müller GmbH".
 */
final readonly class Suchplan
{
    /** Die Bereiche in der Reihenfolge, in der sie in den Ergebnissen stehen. */
    public const BEREICHE = ['kunden', 'projekte', 'aufgaben', 'rechnungen', 'zeiten'];

    /**
     * @param list<string>            $begriffe
     * @param list<string>            $bereiche leer heißt: alle
     * @param \DateTimeImmutable|null $von      erster Tag des Zeitraums, 0 Uhr
     * @param \DateTimeImmutable|null $bis      Tag nach dem letzten, 0 Uhr – das Ende zählt nicht mehr dazu
     */
    public function __construct(
        public array $begriffe,
        public array $bereiche = [],
        public ?\DateTimeImmutable $von = null,
        public ?\DateTimeImmutable $bis = null,
        public bool $vonKi = false,
    ) {
    }

    public static function wortlaut(string $eingabe): self
    {
        return new self([$eingabe]);
    }

    public function umfasst(string $bereich): bool
    {
        return $this->bereiche === [] || \in_array($bereich, $this->bereiche, true);
    }

    public function hatZeitraum(): bool
    {
        return $this->von !== null || $this->bis !== null;
    }
}
