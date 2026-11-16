<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\Assistant\Ausgefuehrt;
use App\Service\Assistant\BadToolInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Der Status einer Rechnung: erstellt → verschickt → bezahlt.
 *
 * Rechnungen selbst entstehen nicht hier: Dazu gehören die Auswahl der
 * Aufgaben, die Vorlage für den Briefkopf, die Signatur und eine
 * Rechnungsnummer, die nie wieder anders lauten darf – das bleibt die
 * Projektseite. Der Assistent darf nur nachtragen, was aus einem Satz
 * hervorgeht („die Telekom-Rechnung ist bezahlt").
 *
 * @phpstan-import-type Plan from Werkzeugbereich
 */
#[AsTaggedItem(priority: 40)]
final class RechnungWerkzeuge extends Werkzeugbasis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        UrlGeneratorInterface $urls,
    ) {
        parent::__construct($urls);
    }

    public function definitionen(): array
    {
        return [
            [
                'name'        => 'rechnung_status',
                'description' => 'Setzt den Status einer Rechnung. "bezahlt" trägt zugleich den Zahlungstag ein,'
                    .' ein anderer Status löscht ihn wieder. Die ID vorher mit suchen oder kunde_lesen holen.'
                    .' Neue Rechnungen entstehen nicht hier, sondern auf der Projektseite.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => ['type' => 'integer', 'description' => 'ID der Rechnung.'],
                        'status' => ['type' => 'string', 'enum' => \array_values(Invoice::STATUS_CHOICES)],
                        'tag'    => ['type' => 'string', 'description' => 'Nur bei "bezahlt": Tag der Zahlung. '.self::DATUM.' Vorgabe ist heute.'],
                    ],
                    'required'   => ['id', 'status'],
                ],
            ],
        ];
    }

    public function schreibende(): array
    {
        return ['rechnung_status'];
    }

    public function planen(string $tool, array $input): array
    {
        if ($tool !== 'rechnung_status') {
            throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".');
        }

        $rechnung = $this->rechnung($input);
        $status   = $this->status($input);

        if ($status === $rechnung->getStatus()) {
            throw new BadToolInput(\sprintf('Rechnung %s steht schon auf "%s".', (string) $rechnung->getNumber(), $status));
        }

        $details = \array_filter([
            'Kunde'   => (string) $rechnung->getCustomer(),
            'Betrag'  => $this->geld((float) $rechnung->getTotalAmount()),
            'Status'  => $rechnung->getStatus().' → '.$status,
            'Bezahlt am' => $status === Invoice::STATUS_PAID ? $this->tag($input)->format('d.m.Y') : '',
        ], static fn (string $wert): bool => \trim($wert) !== '');

        return $this->planned('rechnung_status', 'Rechnung '.$rechnung->getNumber().' auf „'.$status.'“ setzen', $details, $input);
    }

    public function uebernehmen(string $tool, array $input): Ausgefuehrt
    {
        if ($tool !== 'rechnung_status') {
            throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".');
        }

        $rechnung = $this->rechnung($input);
        $status   = $this->status($input);

        $rechnung->setStatus($status);
        $rechnung->setPaidAt(
            $status === Invoice::STATUS_PAID ? \DateTime::createFromImmutable($this->tag($input)) : null,
        );

        $this->em->flush();

        return new Ausgefuehrt(
            \sprintf('Rechnung %s steht auf „%s“.', (string) $rechnung->getNumber(), $status),
            $this->link('admin_invoice_show', $rechnung->getId()),
            'Rechnung '.$rechnung->getNumber(),
        );
    }

    /** @param array<string, mixed> $input */
    private function rechnung(array $input): Invoice
    {
        $id       = (int) ($input['id'] ?? 0);
        $rechnung = $id > 0 ? $this->invoices->find($id) : null;

        if (!$rechnung instanceof Invoice) {
            throw new BadToolInput('Es gibt keine Rechnung mit der ID '.$id.'. Erst mit suchen die richtige ID holen.');
        }

        return $rechnung;
    }

    /** @param array<string, mixed> $input */
    private function status(array $input): string
    {
        $status = \trim((string) ($input['status'] ?? ''));

        if (!\in_array($status, Invoice::STATUS_CHOICES, true)) {
            throw new BadToolInput(\sprintf(
                'Den Status "%s" gibt es nicht. Möglich sind: %s.',
                $status,
                \implode(', ', \array_values(Invoice::STATUS_CHOICES)),
            ));
        }

        return $status;
    }

    /** @param array<string, mixed> $input */
    private function tag(array $input): \DateTimeImmutable
    {
        $tag = \trim((string) ($input['tag'] ?? ''));

        return $tag === '' ? new \DateTimeImmutable('today') : $this->parseDate($tag, 'tag');
    }
}
