<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Sucht nach einem Suchplan in allen Bereichen zugleich – für die Lupe in der
 * Navigation und als Werkzeug des Assistenten.
 *
 * Je Bereich kommen nur die ersten Treffer; wer mehr braucht, sucht in dem
 * Bereich selbst weiter. Kunden, Projekte, Aufgaben und Rechnungen gehören
 * hier allen Angemeldeten gemeinsam – anders als im Portal gibt es deshalb
 * keine Einschränkung auf einen Besitzer.
 */
final class Gesamtsuche
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly InvoiceRepository $invoices,
        private readonly TimeEntryRepository $times,
    ) {
    }

    /**
     * Die Treffer je Bereich, in der Reihenfolge von Suchplan::BEREICHE.
     * Bereiche ohne Treffer fehlen.
     *
     * @return array<string, list<Customer|Project|Task|Invoice|TimeEntry>>
     */
    public function suchen(Suchplan $plan, int $jeBereich = 8): array
    {
        $treffer = [];

        foreach (Suchplan::BEREICHE as $bereich) {
            if (!$plan->umfasst($bereich)) {
                continue;
            }

            $liste = match ($bereich) {
                'kunden'     => $this->kunden($plan, $jeBereich),
                'projekte'   => $this->projekte($plan, $jeBereich),
                'aufgaben'   => $this->aufgaben($plan, $jeBereich),
                'rechnungen' => $this->rechnungen($plan, $jeBereich),
                'zeiten'     => $this->zeiten($plan, $jeBereich),
            };

            if ($liste !== []) {
                $treffer[$bereich] = $liste;
            }
        }

        return $treffer;
    }

    /**
     * Ein Kunde hat kein Datum – ein Zeitraum lässt ihn deshalb unberührt.
     *
     * @return list<Customer>
     */
    private function kunden(Suchplan $plan, int $anzahl): array
    {
        $qb = $this->customers->createQueryBuilder('k');

        $this->passend($qb, [
            'k.firstname', 'k.lastname', 'k.companyName', 'k.email', 'k.phone',
            'k.mobile', 'k.street', 'k.plz', 'k.city', 'k.website',
        ], $plan->begriffe);

        return $qb->orderBy('k.lastname', 'ASC')->addOrderBy('k.firstname', 'ASC')
            ->setMaxResults($anzahl)
            ->getQuery()->getResult();
    }

    /** @return list<Project> */
    private function projekte(Suchplan $plan, int $anzahl): array
    {
        $qb = $this->projects->createQueryBuilder('p')
            ->innerJoin('p.customer', 'k')->addSelect('k');

        $this->passend($qb, ['p.name', 'p.description', 'k.companyName', 'k.lastname'], $plan->begriffe);
        $this->imZeitraum($qb, 'p.createdAt', $plan);

        return $qb->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->setMaxResults($anzahl)
            ->getQuery()->getResult();
    }

    /**
     * Offene vor abgerechneten – in einer Rechnung gelandete Aufgaben sind
     * Geschichte, gefragt ist meist das, woran noch etwas zu tun ist.
     *
     * @return list<Task>
     */
    private function aufgaben(Suchplan $plan, int $anzahl): array
    {
        $qb = $this->tasks->createQueryBuilder('a')
            ->innerJoin('a.project', 'p')->addSelect('p')
            ->innerJoin('p.customer', 'k')->addSelect('k');

        $this->passend($qb, ['a.name', 'a.description', 'p.name'], $plan->begriffe);
        $this->imZeitraum($qb, 'a.createdAt', $plan);

        return $qb
            ->addSelect('CASE WHEN a.invoiced = true THEN 1 ELSE 0 END AS HIDDEN abgerechnetZuletzt')
            ->orderBy('abgerechnetZuletzt', 'ASC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($anzahl)
            ->getQuery()->getResult();
    }

    /** @return list<Invoice> */
    private function rechnungen(Suchplan $plan, int $anzahl): array
    {
        $qb = $this->invoices->createQueryBuilder('r')
            ->innerJoin('r.customer', 'k')->addSelect('k')
            ->leftJoin('r.project', 'p')->addSelect('p');

        $this->passend($qb, [
            'r.number', 'r.status', 'p.name', 'k.companyName', 'k.lastname', 'k.firstname',
        ], $plan->begriffe);
        $this->imZeitraum($qb, 'r.invoiceDate', $plan);

        return $qb->orderBy('r.invoiceDate', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults($anzahl)
            ->getQuery()->getResult();
    }

    /** @return list<TimeEntry> */
    private function zeiten(Suchplan $plan, int $anzahl): array
    {
        $qb = $this->times->createQueryBuilder('z')
            ->innerJoin('z.task', 'a')->addSelect('a')
            ->innerJoin('a.project', 'p')->addSelect('p');

        $this->passend($qb, ['z.note', 'a.name', 'p.name'], $plan->begriffe);
        $this->imZeitraum($qb, 'z.workDate', $plan);

        return $qb->orderBy('z.workDate', 'DESC')->addOrderBy('z.id', 'DESC')
            ->setMaxResults($anzahl)
            ->getQuery()->getResult();
    }

    /**
     * Die Bedingung hinter allen Bereichen: Einer der Begriffe muss passen,
     * und ein Begriff passt, wenn jedes seiner Wörter in einem der Felder steht.
     *
     * @param list<string> $felder
     * @param list<string> $begriffe
     */
    private function passend(QueryBuilder $qb, array $felder, array $begriffe): void
    {
        $oder = $qb->expr()->orX();

        foreach ($begriffe as $b => $begriff) {
            $und = $qb->expr()->andX();

            foreach (\preg_split('/\s+/u', \trim($begriff), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $w => $wort) {
                $platz = \sprintf('such_%d_%d', $b, $w);

                $und->add('('.\implode(' OR ', \array_map(
                    static fn (string $feld): string => $feld.' LIKE :'.$platz,
                    $felder,
                )).')');

                // Die Platzhalter von LIKE gelten im Suchbegriff wörtlich.
                $qb->setParameter($platz, '%'.\addcslashes($wort, '%_\\').'%');
            }

            if ($und->count() > 0) {
                $oder->add($und);
            }
        }

        $qb->andWhere($oder->count() > 0 ? $oder : '1 = 0');
    }

    /** Grenzt auf den Zeitraum des Plans ein; das Ende zählt nicht mehr dazu. */
    private function imZeitraum(QueryBuilder $qb, string $feld, Suchplan $plan): void
    {
        if ($plan->von !== null) {
            $qb->andWhere($feld.' >= :such_von')->setParameter('such_von', $plan->von);
        }

        if ($plan->bis !== null) {
            $qb->andWhere($feld.' < :such_bis')->setParameter('such_bis', $plan->bis);
        }
    }
}
