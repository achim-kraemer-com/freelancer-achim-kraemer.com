<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Service\Assistant\Ausgefuehrt;
use App\Service\Assistant\BadToolInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Aufgaben anlegen und ändern und Zeit darauf erfassen.
 *
 * Eine Aufgabe wird entweder nach Stunden abgerechnet (zum Stundensatz aus
 * Task::HOURLY_RATE) oder zum Festpreis – beides zugleich geht nicht. Was in
 * einer Rechnung steht, ist gesperrt: Diese Werkzeuge fassen es nicht an.
 *
 * @phpstan-import-type Plan from Werkzeugbereich
 */
#[AsTaggedItem(priority: 50)]
final class AufgabenWerkzeuge extends Werkzeugbasis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        UrlGeneratorInterface $urls,
    ) {
        parent::__construct($urls);
    }

    public function definitionen(): array
    {
        $status = ['type' => 'string', 'enum' => \array_values(Task::STATUS_CHOICES)];

        return [
            [
                'name'        => 'aufgabe_anlegen',
                'description' => 'Legt eine Aufgabe in einem Projekt an. Die Projekt-ID vorher mit suchen oder'
                    .' kunde_lesen holen. Entweder "stunden" oder "festpreis" angeben, nicht beides.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'projekt_id'   => ['type' => 'integer', 'description' => 'ID des Projekts.'],
                        'name'         => ['type' => 'string'],
                        'beschreibung' => ['type' => 'string'],
                        'stunden'      => ['type' => 'number', 'description' => 'Geleistete Stunden; wird mit '.Task::HOURLY_RATE.' € je Stunde abgerechnet.'],
                        'festpreis'    => ['type' => 'number', 'description' => 'Fester Betrag in Euro statt einer Abrechnung nach Stunden.'],
                        'status'       => $status + ['description' => 'Vorgabe ist "'.Task::STATUS_OPEN.'".'],
                    ],
                    'required'   => ['projekt_id', 'name'],
                ],
            ],
            [
                'name'        => 'aufgabe_aendern',
                'description' => 'Ändert eine Aufgabe: Name, Beschreibung, Stunden, Festpreis oder Status. Nur die'
                    .' Felder angeben, die sich ändern. Abgerechnete Aufgaben sind gesperrt. Die ID vorher mit'
                    .' suchen oder projekt_lesen holen.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'integer', 'description' => 'ID der Aufgabe.'],
                        'name'         => ['type' => 'string'],
                        'beschreibung' => ['type' => 'string'],
                        'stunden'      => ['type' => 'number', 'description' => 'Neuer Stand der Stunden, nicht die Differenz. Setzt einen Festpreis zurück.'],
                        'festpreis'    => ['type' => 'number', 'description' => 'Fester Betrag in Euro; die Stunden zählen dann nicht mehr für den Preis.'],
                        'status'       => $status,
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'zeit_erfassen',
                'description' => 'Schreibt Arbeitszeit auf eine Aufgabe: legt einen Zeiteintrag an und erhöht die'
                    .' Stunden der Aufgabe um dieselbe Zeit – wie die Stoppuhr unter Zeiterfassung. Entweder'
                    .' "minuten" oder "stunden" angeben.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'aufgabe_id' => ['type' => 'integer', 'description' => 'ID der Aufgabe.'],
                        'minuten'    => ['type' => 'integer'],
                        'stunden'    => ['type' => 'number'],
                        'tag'        => ['type' => 'string', 'description' => 'Tag der Arbeit. '.self::DATUM.' Vorgabe ist heute.'],
                        'notiz'      => ['type' => 'string', 'description' => 'Was in der Zeit getan wurde.'],
                    ],
                    'required'   => ['aufgabe_id'],
                ],
            ],
        ];
    }

    public function schreibende(): array
    {
        return ['aufgabe_anlegen', 'aufgabe_aendern', 'zeit_erfassen'];
    }

    public function planen(string $tool, array $input): array
    {
        return match ($tool) {
            'aufgabe_anlegen' => $this->planAnlegen($input),
            'aufgabe_aendern' => $this->planAendern($input),
            'zeit_erfassen'   => $this->planZeit($input),
            default           => throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".'),
        };
    }

    public function uebernehmen(string $tool, array $input): Ausgefuehrt
    {
        return match ($tool) {
            'aufgabe_anlegen' => $this->anlegen($input),
            'aufgabe_aendern' => $this->aendern($input),
            'zeit_erfassen'   => $this->zeitErfassen($input),
            default           => throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".'),
        };
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     */
    private function planAnlegen(array $input): array
    {
        $projekt = $this->projekt($input);
        $name    = $this->requireText($input, 'name');
        $preise  = $this->preise($input);
        $status  = $this->status($input) ?? Task::STATUS_OPEN;

        $details = \array_filter([
            'Projekt'      => $projekt->getName().' · '.$projekt->getCustomer(),
            'Abrechnung'   => $preise['festpreis'] !== null
                ? 'Festpreis '.$this->geld($preise['festpreis'])
                : ($preise['stunden'] !== null
                    ? $this->stunden($preise['stunden']).' × '.$this->geld(Task::HOURLY_RATE).' = '.$this->geld($preise['stunden'] * Task::HOURLY_RATE)
                    : null),
            'Status'       => $status,
            'Beschreibung' => \mb_strimwidth(\trim((string) ($input['beschreibung'] ?? '')), 0, 400, '…'),
        ], static fn (?string $wert): bool => $wert !== null && \trim($wert) !== '');

        return $this->planned('aufgabe_anlegen', 'Aufgabe anlegen: „'.$name.'“', $details, $input);
    }

    /** @param array<string, mixed> $input */
    private function anlegen(array $input): Ausgefuehrt
    {
        $projekt = $this->projekt($input);
        $preise  = $this->preise($input);

        $aufgabe = (new Task())
            ->setProject($projekt)
            ->setName($this->requireText($input, 'name'))
            ->setDescription(\trim((string) ($input['beschreibung'] ?? '')) ?: null)
            ->setStatus($this->status($input) ?? Task::STATUS_OPEN);

        if ($preise['festpreis'] !== null) {
            $aufgabe->setPrice((string) $preise['festpreis']);
        } elseif ($preise['stunden'] !== null) {
            $aufgabe->setHours((string) $preise['stunden']);
        }

        $this->em->persist($aufgabe);
        $this->em->flush();

        return new Ausgefuehrt(
            'Aufgabe „'.$aufgabe->getName().'“ im Projekt '.$projekt->getName().' angelegt.',
            $this->link('admin_task_edit', $aufgabe->getId()),
            (string) $aufgabe->getName(),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     */
    private function planAendern(array $input): array
    {
        $aufgabe = $this->aufgabe($input, 'id');
        $preise  = $this->preise($input);
        $status  = $this->status($input);
        $name    = \trim((string) ($input['name'] ?? ''));
        $text    = \array_key_exists('beschreibung', $input) ? \trim((string) $input['beschreibung']) : null;

        $details = \array_filter([
            'Projekt'      => $aufgabe->getProject()?->getName().' · '.$aufgabe->getProject()?->getCustomer(),
            'Neuer Name'   => $name,
            'Status'       => $status === null ? null : $aufgabe->getStatus().' → '.$status,
            'Stunden'      => $preise['stunden'] === null ? null : $this->stunden((float) ($aufgabe->getHours() ?? '0')).' → '.$this->stunden($preise['stunden']),
            'Festpreis'    => $preise['festpreis'] === null ? null : $this->geld($preise['festpreis']),
            'Beschreibung' => $text === null ? null : ($text === '' ? '(wird geleert)' : \mb_strimwidth($text, 0, 400, '…')),
        ], static fn (?string $wert): bool => $wert !== null && \trim($wert) !== '');

        if (\count($details) <= 1) {
            throw new BadToolInput('Es ist nichts zu ändern angegeben – Name, Beschreibung, Stunden, Festpreis oder Status setzen.');
        }

        return $this->planned('aufgabe_aendern', 'Aufgabe ändern: „'.$aufgabe->getName().'“', $details, $input);
    }

    /** @param array<string, mixed> $input */
    private function aendern(array $input): Ausgefuehrt
    {
        $aufgabe = $this->aufgabe($input, 'id');
        $preise  = $this->preise($input);
        $status  = $this->status($input);
        $name    = \trim((string) ($input['name'] ?? ''));

        if ($name !== '') {
            $aufgabe->setName($name);
        }

        if (\array_key_exists('beschreibung', $input)) {
            $aufgabe->setDescription(\trim((string) $input['beschreibung']) ?: null);
        }

        if ($status !== null) {
            $aufgabe->setStatus($status);
        }

        // Festpreis und Stunden schließen sich aus: Was gesetzt wird, löscht
        // das andere – sonst stünde in der Aufgabe ein Preis, den niemand
        // gemeint hat.
        if ($preise['festpreis'] !== null) {
            $aufgabe->setPrice((string) $preise['festpreis']);
        } elseif ($preise['stunden'] !== null) {
            $aufgabe->setHours((string) $preise['stunden']);
            $aufgabe->setPrice(null);
        }

        $this->em->flush();

        return new Ausgefuehrt(
            'Aufgabe „'.$aufgabe->getName().'“ geändert.',
            $this->link('admin_task_edit', $aufgabe->getId()),
            (string) $aufgabe->getName(),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     */
    private function planZeit(array $input): array
    {
        $aufgabe = $this->aufgabe($input, 'aufgabe_id');
        $minuten = $this->minuten($input);
        $tag     = $this->tag($input);
        $neu     = \round((float) ($aufgabe->getHours() ?? '0') + $minuten / 60, 2);

        $details = \array_filter([
            'Projekt'      => $aufgabe->getProject()?->getName().' · '.$aufgabe->getProject()?->getCustomer(),
            'Tag'          => $tag->format('d.m.Y'),
            'Dauer'        => $minuten.' Min. ('.$this->stunden($minuten / 60).')',
            'Neuer Stand'  => $this->stunden($neu).($aufgabe->isHourly() ? ' = '.$this->geld($neu * Task::HOURLY_RATE) : ' (Festpreis bleibt)'),
            'Notiz'        => \mb_strimwidth(\trim((string) ($input['notiz'] ?? '')), 0, 400, '…'),
        ], static fn (string $wert): bool => \trim($wert) !== '');

        return $this->planned('zeit_erfassen', 'Zeit erfassen auf „'.$aufgabe->getName().'“', $details, $input);
    }

    /** @param array<string, mixed> $input */
    private function zeitErfassen(array $input): Ausgefuehrt
    {
        $aufgabe = $this->aufgabe($input, 'aufgabe_id');
        $minuten = $this->minuten($input);
        $notiz   = \trim((string) ($input['notiz'] ?? ''));

        // Wie beim Beenden der Stoppuhr: der Eintrag fürs Protokoll, und die
        // Zeit wandert in die Stunden der Aufgabe.
        $eintrag = (new TimeEntry())
            ->setTask($aufgabe)
            ->setWorkDate(\DateTime::createFromImmutable($this->tag($input)))
            ->setSeconds($minuten * 60)
            ->setMinutes($minuten)
            ->setNote($notiz !== '' ? $notiz : null);

        $neu = \round((float) ($aufgabe->getHours() ?? '0') + $minuten / 60, 2);
        $aufgabe->setHours((string) $neu);

        $this->em->persist($eintrag);
        $this->em->flush();

        return new Ausgefuehrt(
            \sprintf('%d Min. auf „%s“ erfasst. Neuer Stand: %s.', $minuten, (string) $aufgabe->getName(), $this->stunden($neu)),
            $this->link('admin_task_edit', $aufgabe->getId()),
            (string) $aufgabe->getName(),
        );
    }

    /** @param array<string, mixed> $input */
    private function projekt(array $input): Project
    {
        $id      = (int) ($input['projekt_id'] ?? 0);
        $projekt = $id > 0 ? $this->projects->find($id) : null;

        if (!$projekt instanceof Project) {
            throw new BadToolInput('Es gibt kein Projekt mit der ID '.$id.'. Erst mit suchen die richtige ID holen.');
        }

        return $projekt;
    }

    /** @param array<string, mixed> $input */
    private function aufgabe(array $input, string $feld): Task
    {
        $id      = (int) ($input[$feld] ?? 0);
        $aufgabe = $id > 0 ? $this->tasks->find($id) : null;

        if (!$aufgabe instanceof Task) {
            throw new BadToolInput('Es gibt keine Aufgabe mit der ID '.$id.'. Erst mit suchen die richtige ID holen.');
        }

        if ($aufgabe->isInvoiced()) {
            throw new BadToolInput(\sprintf(
                'Die Aufgabe „%s" steht in Rechnung %s und ist gesperrt. Was abgerechnet ist, bleibt, wie es ist.',
                (string) $aufgabe->getName(),
                (string) $aufgabe->getInvoice()?->getNumber(),
            ));
        }

        return $aufgabe;
    }

    /**
     * Stunden und Festpreis aus dem Aufruf. Beides zugleich lehnt die Aufgabe
     * ab – welcher Preis dann gälte, wüsste niemand.
     *
     * @param array<string, mixed> $input
     *
     * @return array{stunden: ?float, festpreis: ?float}
     */
    private function preise(array $input): array
    {
        $stunden   = $this->zahl($input, 'stunden');
        $festpreis = $this->zahl($input, 'festpreis');

        if ($stunden !== null && $festpreis !== null) {
            throw new BadToolInput('Eine Aufgabe hat entweder Stunden oder einen Festpreis, nicht beides.');
        }

        return ['stunden' => $stunden, 'festpreis' => $festpreis];
    }

    /** @param array<string, mixed> $input */
    private function status(array $input): ?string
    {
        $status = \trim((string) ($input['status'] ?? ''));

        if ($status === '') {
            return null;
        }

        if (!\in_array($status, Task::STATUS_CHOICES, true)) {
            throw new BadToolInput(\sprintf(
                'Den Status "%s" gibt es nicht. Möglich sind: %s.',
                $status,
                \implode(', ', \array_values(Task::STATUS_CHOICES)),
            ));
        }

        return $status;
    }

    /** @param array<string, mixed> $input */
    private function minuten(array $input): int
    {
        $minuten = $this->zahl($input, 'minuten');
        $stunden = $this->zahl($input, 'stunden');

        if ($minuten === null && $stunden === null) {
            throw new BadToolInput('Wie lange gearbeitet wurde, fehlt – "minuten" oder "stunden" angeben.');
        }

        // Angefangene Minuten zählen voll, wie beim Beenden der Stoppuhr.
        $gesamt = (int) \ceil($minuten ?? $stunden * 60);

        if ($gesamt <= 0) {
            throw new BadToolInput('Die Dauer muss größer als null sein.');
        }

        if ($gesamt > 24 * 60) {
            throw new BadToolInput('Mehr als 24 Stunden an einem Tag sind nicht plausibel – bitte nachfragen.');
        }

        return $gesamt;
    }

    /** @param array<string, mixed> $input */
    private function tag(array $input): \DateTimeImmutable
    {
        $tag = \trim((string) ($input['tag'] ?? ''));

        return $tag === '' ? new \DateTimeImmutable('today') : $this->parseDate($tag, 'tag');
    }
}
