<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimeEntryRepository;
use App\Service\Assistant\BadToolInput;
use App\Service\Suche\Gesamtsuche;
use App\Service\Suche\Suchplan;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Die lesenden Werkzeuge: die Suche über alle Bereiche, ein Kunde oder ein
 * Projekt im Ganzen und die Zahlen eines Zeitraums.
 *
 * Sie laufen sofort und liefern dem Modell die IDs, die es zum Ändern
 * braucht, und die Pfade, unter denen ein Eintrag in der Verwaltung steht.
 */
#[AsTaggedItem(priority: 70)]
final class LeseWerkzeuge extends Werkzeugbasis
{
    public function __construct(
        private readonly Gesamtsuche $suche,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly InvoiceRepository $invoices,
        private readonly TimeEntryRepository $times,
        UrlGeneratorInterface $urls,
    ) {
        parent::__construct($urls);
    }

    public function definitionen(): array
    {
        $datum = self::DATUM;

        return [
            [
                'name'        => 'suchen',
                'description' => 'Durchsucht Kunden, Projekte, Aufgaben, Rechnungen und erfasste Zeiten zugleich und'
                    .' liefert je Bereich die ersten Treffer mit ID, Eckdaten und Pfad. Der erste Griff für jede Frage'
                    .' nach etwas, das in der Verwaltung steht, und der Weg zu jeder ID.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'begriffe' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Ein bis acht Suchbegriffe; ein Treffer muss einen davon enthalten. Ein'
                                .' Begriff aus mehreren Wörtern passt, wenn alle Wörter vorkommen. Kurze Wörter, die'
                                .' wörtlich dastehen könnten (Namen, Firmen, Rechnungsnummern), dazu naheliegende'
                                .' Abwandlungen.',
                        ],
                        'bereiche' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string', 'enum' => Suchplan::BEREICHE],
                            'description' => 'Nur diese Bereiche durchsuchen. Weglassen für alle.',
                        ],
                        'von' => ['type' => 'string', 'description' => 'Optional: erster Tag eines Zeitraums. '.$datum],
                        'bis' => ['type' => 'string', 'description' => 'Optional: letzter Tag eines Zeitraums. '.$datum],
                    ],
                    'required'   => ['begriffe'],
                ],
            ],
            [
                'name'        => 'kunde_lesen',
                'description' => 'Liest einen Kunden vollständig: Kontaktdaten, alle Projekte mit offenem und'
                    .' abgerechnetem Wert und alle Rechnungen mit Status.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'ID aus suchen.']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'projekt_lesen',
                'description' => 'Liest ein Projekt vollständig: alle Aufgaben mit Stunden oder Festpreis, Status und'
                    .' Abrechnung, die erfassten Zeiten und die Summen.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'ID aus suchen.']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'zahlen_lesen',
                'description' => 'Rechnet die Zahlen eines Zeitraums zusammen: abgerechnet und bezahlt, was noch'
                    .' aussteht, überfällige Rechnungen, erfasste Stunden und der Wert der noch nicht abgerechneten'
                    .' Aufgaben. Für jede Frage nach Umsatz, offenen Posten oder Auslastung.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'von' => ['type' => 'string', 'description' => 'Erster Tag des Zeitraums. '.$datum],
                        'bis' => ['type' => 'string', 'description' => 'Letzter Tag des Zeitraums. '.$datum],
                    ],
                    'required'   => ['von', 'bis'],
                ],
            ],
        ];
    }

    public function lesen(string $tool, array $input): string
    {
        return match ($tool) {
            'suchen'        => $this->suchen($input),
            'kunde_lesen'   => $this->kundeLesen($input),
            'projekt_lesen' => $this->projektLesen($input),
            'zahlen_lesen'  => $this->zahlenLesen($input),
            default         => $this->unbekannt($tool),
        };
    }

    /**
     * Die Suche hinter der Lupe, für das Modell: dieselbe Gesamtsuche, nur
     * dass es die Begriffe selbst wählt.
     *
     * @param array<string, mixed> $input
     */
    private function suchen(array $input): string
    {
        $begriffe = [];

        foreach (\is_array($input['begriffe'] ?? null) ? $input['begriffe'] : [$input['begriffe'] ?? ''] as $begriff) {
            $begriff = \is_string($begriff) ? \mb_substr(\trim($begriff), 0, 80) : '';

            if ($begriff !== '') {
                $begriffe[] = $begriff;
            }
        }

        if ($begriffe === []) {
            throw new BadToolInput('Mindestens ein Suchbegriff ist nötig.');
        }

        $von = \trim((string) ($input['von'] ?? '')) === '' ? null : $this->parseDate((string) $input['von'], 'von');
        $bis = \trim((string) ($input['bis'] ?? '')) === '' ? null : $this->parseDate((string) $input['bis'], 'bis')->modify('+1 day');

        $plan = new Suchplan(
            \array_slice($begriffe, 0, 8),
            \is_array($input['bereiche'] ?? null) ? \array_values(\array_intersect(Suchplan::BEREICHE, $input['bereiche'])) : [],
            $von,
            $bis,
        );

        $ergebnis = [];

        foreach ($this->suche->suchen($plan, 10) as $bereich => $liste) {
            $ergebnis[$bereich] = \array_map(fn (object $t): array => match (true) {
                $t instanceof Customer  => $this->kundeZeile($t),
                $t instanceof Project   => $this->projektZeile($t),
                $t instanceof Task      => $this->aufgabeZeile($t),
                $t instanceof Invoice   => $this->rechnungZeile($t),
                default                 => $this->zeitZeile($t),
            }, $liste);
        }

        return $this->encode([
            'gesucht' => $plan->begriffe,
            'treffer' => $ergebnis,
            'hinweis' => $ergebnis === []
                ? 'Nichts gefunden. Andere oder kürzere Begriffe versuchen.'
                : 'Je Bereich höchstens 10 Treffer. Für alles zu einem Kunden kunde_lesen, zu einem Projekt projekt_lesen.',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function kundeLesen(array $input): string
    {
        $id    = (int) ($input['id'] ?? 0);
        $kunde = $id > 0 ? $this->customers->find($id) : null;

        if (!$kunde instanceof Customer) {
            return 'Fehler: Es gibt keinen Kunden mit der ID '.$id.'. Erst mit suchen die richtige ID holen.';
        }

        $projekte = [];

        foreach ($kunde->getProjects() as $projekt) {
            $projekte[] = $this->projektZeile($projekt);
        }

        $rechnungen = [];

        foreach ($kunde->getInvoices() as $rechnung) {
            $rechnungen[] = $this->rechnungZeile($rechnung);
        }

        return $this->encode($this->kundeZeile($kunde) + [
            'adresse'    => $this->adresse($kunde),
            'webseite'   => $kunde->getWebsite(),
            'aktiv'      => $kunde->isActive(),
            'projekte'   => $projekte,
            'rechnungen' => $rechnungen,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function projektLesen(array $input): string
    {
        $id      = (int) ($input['id'] ?? 0);
        $projekt = $id > 0 ? $this->projects->find($id) : null;

        if (!$projekt instanceof Project) {
            return 'Fehler: Es gibt kein Projekt mit der ID '.$id.'. Erst mit suchen die richtige ID holen.';
        }

        $aufgaben = [];
        $zeiten   = [];
        $stunden  = 0.0;

        foreach ($projekt->getTasks() as $aufgabe) {
            $aufgaben[] = $this->aufgabeZeile($aufgabe);

            foreach ($aufgabe->getTimeEntries() as $eintrag) {
                $stunden += $eintrag->getHours();

                if (\count($zeiten) < self::MAX_TREFFER) {
                    $zeiten[] = $this->zeitZeile($eintrag);
                }
            }
        }

        return $this->encode($this->projektZeile($projekt) + [
            'beschreibung'    => $this->kurz($projekt->getDescription()),
            'aufgaben'        => $aufgaben,
            'erfasste_zeiten' => $zeiten,
            'erfasste_stunden' => \round($stunden, 2),
            'stundensatz'     => Task::HOURLY_RATE,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function zahlenLesen(array $input): string
    {
        $von = $this->parseDate((string) ($input['von'] ?? ''), 'von');
        $bis = $this->parseDate((string) ($input['bis'] ?? ''), 'bis')->modify('+1 day');

        if ($bis <= $von) {
            throw new BadToolInput('"bis" muss auf oder nach "von" liegen.');
        }

        /** @var list<Invoice> $rechnungen */
        $rechnungen = $this->invoices->createQueryBuilder('r')
            ->andWhere('r.invoiceDate >= :von')->setParameter('von', $von)
            ->andWhere('r.invoiceDate < :bis')->setParameter('bis', $bis)
            ->getQuery()->getResult();

        $summen = [];
        $gesamt = 0.0;

        foreach ($rechnungen as $rechnung) {
            $status          = $rechnung->getStatus();
            $betrag          = (float) $rechnung->getTotalAmount();
            $summen[$status] ??= ['anzahl' => 0, 'summe' => 0.0];
            ++$summen[$status]['anzahl'];
            $summen[$status]['summe'] += $betrag;
            $gesamt                   += $betrag;
        }

        foreach ($summen as $status => $zahlen) {
            $summen[$status]['summe'] = \round($zahlen['summe'], 2);
        }

        // Offenes und Überfälliges gelten unabhängig vom Zeitraum: Eine alte
        // unbezahlte Rechnung ist genau die, nach der gefragt wird.
        $offen      = 0.0;
        $ueberfaell = [];

        foreach ($this->invoices->findOpen() as $rechnung) {
            $offen += (float) $rechnung->getTotalAmount();

            if ($rechnung->isOverdue()) {
                $ueberfaell[] = $this->rechnungZeile($rechnung);
            }
        }

        $stunden = 0.0;

        /** @var list<TimeEntry> $eintraege */
        $eintraege = $this->times->createQueryBuilder('z')
            ->andWhere('z.workDate >= :von')->setParameter('von', $von)
            ->andWhere('z.workDate < :bis')->setParameter('bis', $bis)
            ->getQuery()->getResult();

        foreach ($eintraege as $eintrag) {
            $stunden += $eintrag->getHours();
        }

        // Was erarbeitet, aber noch nicht in eine Rechnung gewandert ist.
        $nichtAbgerechnet = 0.0;

        foreach ($this->projects->findAll() as $projekt) {
            $nichtAbgerechnet += $projekt->getOpenTotalCost();
        }

        return $this->encode([
            'zeitraum' => ['von' => $von->format('Y-m-d'), 'bis' => $bis->modify('-1 day')->format('Y-m-d')],
            'rechnungen_im_zeitraum' => [
                'anzahl'      => \count($rechnungen),
                'summe'       => \round($gesamt, 2),
                'nach_status' => $summen,
            ],
            'offene_rechnungen_gesamt'   => \round($offen, 2),
            'ueberfaellige_rechnungen'   => $ueberfaell,
            'erfasste_stunden_im_zeitraum' => \round($stunden, 2),
            'nicht_abgerechnete_aufgaben'  => \round($nichtAbgerechnet, 2),
            'hinweis' => 'Alle Beträge in Euro, netto (Kleinunternehmer ohne Umsatzsteuer). Offenes und Überfälliges'
                .' zählen über den Zeitraum hinaus, weil eine unbezahlte Rechnung älter sein kann.',
        ]);
    }

    /** @return array<string, mixed> */
    private function kundeZeile(Customer $kunde): array
    {
        return [
            'id'      => $kunde->getId(),
            'kunde'   => (string) $kunde,
            'firma'   => $kunde->getCompanyName(),
            'email'   => $kunde->getEmail(),
            'telefon' => $kunde->getPhone(),
            'mobil'   => $kunde->getMobile(),
            'ort'     => $kunde->getCity(),
            'link'    => $this->link('admin_customer_show', $kunde->getId()),
        ];
    }

    /** @return array<string, mixed> */
    private function projektZeile(Project $projekt): array
    {
        return [
            'id'             => $projekt->getId(),
            'projekt'        => $projekt->getName(),
            'kunde'          => (string) $projekt->getCustomer(),
            'kunde_id'       => $projekt->getCustomer()?->getId(),
            'angelegt_am'    => $projekt->getCreatedAt()?->format('Y-m-d'),
            'wert_gesamt'    => \round($projekt->getTotalCost(), 2),
            'wert_offen'     => \round($projekt->getOpenTotalCost(), 2),
            'link'           => $this->link('admin_project_show', $projekt->getId()),
        ];
    }

    /** @return array<string, mixed> */
    private function aufgabeZeile(Task $aufgabe): array
    {
        return [
            'id'           => $aufgabe->getId(),
            'aufgabe'      => $aufgabe->getName(),
            'projekt'      => $aufgabe->getProject()?->getName(),
            'projekt_id'   => $aufgabe->getProject()?->getId(),
            'status'       => $aufgabe->getStatus(),
            'abrechnung'   => $aufgabe->isHourly() ? 'stündlich' : 'Festpreis',
            'stunden'      => $aufgabe->getHours() === null ? null : (float) $aufgabe->getHours(),
            'festpreis'    => $aufgabe->getPrice() === null ? null : (float) $aufgabe->getPrice(),
            'wert'         => \round($aufgabe->getCost(), 2),
            'abgerechnet'  => $aufgabe->isInvoiced(),
            'beschreibung' => $this->kurz($aufgabe->getDescription()),
            'link'         => $this->link('admin_task_edit', $aufgabe->getId()),
        ];
    }

    /** @return array<string, mixed> */
    private function rechnungZeile(Invoice $rechnung): array
    {
        return [
            'id'          => $rechnung->getId(),
            'nummer'      => $rechnung->getNumber(),
            'kunde'       => (string) $rechnung->getCustomer(),
            'projekt'     => $rechnung->getProject()?->getName(),
            'datum'       => $rechnung->getInvoiceDate()?->format('Y-m-d'),
            'faellig_am'  => $rechnung->getDueDate()?->format('Y-m-d'),
            'bezahlt_am'  => $rechnung->getPaidAt()?->format('Y-m-d'),
            'betrag'      => (float) $rechnung->getTotalAmount(),
            'status'      => $rechnung->getStatus(),
            'ueberfaellig' => $rechnung->isOverdue(),
            'link'        => $this->link('admin_invoice_show', $rechnung->getId()),
        ];
    }

    /** @return array<string, mixed> */
    private function zeitZeile(TimeEntry $eintrag): array
    {
        $aufgabe = $eintrag->getTask();

        return [
            'id'         => $eintrag->getId(),
            'tag'        => $eintrag->getWorkDate()?->format('Y-m-d'),
            'minuten'    => $eintrag->getMinutes(),
            'stunden'    => \round($eintrag->getHours(), 2),
            'notiz'      => $this->kurz($eintrag->getNote()),
            'aufgabe'    => $aufgabe?->getName(),
            'aufgabe_id' => $aufgabe?->getId(),
            'projekt'    => $aufgabe?->getProject()?->getName(),
            'link'       => $aufgabe === null ? null : $this->link('admin_task_edit', $aufgabe->getId()),
        ];
    }

    private function kurz(?string $text): ?string
    {
        if ($text === null || \trim($text) === '') {
            return null;
        }

        return \mb_strimwidth($text, 0, self::MAX_TEXT, '…');
    }

    private function adresse(Customer $kunde): ?string
    {
        $strasse = \trim($kunde->getStreet().' '.$kunde->getHousenumber());
        $ort     = \trim($kunde->getPlz().' '.$kunde->getCity());
        $zeilen  = \array_filter([$strasse, $ort, (string) $kunde->getCountry()], static fn (string $z): bool => $z !== '');

        return $zeilen === [] ? null : \implode(', ', $zeilen);
    }
}
