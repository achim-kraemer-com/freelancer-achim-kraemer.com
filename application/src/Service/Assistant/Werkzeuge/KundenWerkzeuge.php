<?php

declare(strict_types=1);

namespace App\Service\Assistant\Werkzeuge;

use App\Entity\Customer;
use App\Entity\Project;
use App\Repository\CustomerRepository;
use App\Service\Assistant\Ausgefuehrt;
use App\Service\Assistant\BadToolInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Neue Kunden und neue Projekte.
 *
 * @phpstan-import-type Plan from Werkzeugbereich
 */
#[AsTaggedItem(priority: 60)]
final class KundenWerkzeuge extends Werkzeugbasis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerRepository $customers,
        private readonly ValidatorInterface $validator,
        UrlGeneratorInterface $urls,
    ) {
        parent::__construct($urls);
    }

    public function definitionen(): array
    {
        return [
            [
                'name'        => 'kunde_anlegen',
                'description' => 'Legt einen Kunden an. Vorher mit suchen prüfen, ob es ihn schon gibt.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'vorname'     => ['type' => 'string'],
                        'nachname'    => ['type' => 'string', 'description' => 'Bei einer Firma ohne Ansprechpartner der Firmenname.'],
                        'firma'       => ['type' => 'string'],
                        'email'       => ['type' => 'string', 'description' => 'Pflicht – ohne E-Mail lässt sich keine Rechnung verschicken.'],
                        'telefon'     => ['type' => 'string'],
                        'mobil'       => ['type' => 'string'],
                        'strasse'     => ['type' => 'string', 'description' => 'Ohne Hausnummer.'],
                        'hausnummer'  => ['type' => 'string'],
                        'plz'         => ['type' => 'string'],
                        'ort'         => ['type' => 'string'],
                        'land'        => ['type' => 'string'],
                        'webseite'    => ['type' => 'string'],
                    ],
                    'required'   => ['vorname', 'nachname', 'email'],
                ],
            ],
            [
                'name'        => 'projekt_anlegen',
                'description' => 'Legt ein Projekt für einen Kunden an. Die Kunden-ID vorher mit suchen holen.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'kunde_id'     => ['type' => 'integer', 'description' => 'ID des Kunden aus suchen.'],
                        'name'         => ['type' => 'string'],
                        'beschreibung' => ['type' => 'string'],
                    ],
                    'required'   => ['kunde_id', 'name'],
                ],
            ],
        ];
    }

    public function schreibende(): array
    {
        return ['kunde_anlegen', 'projekt_anlegen'];
    }

    public function planen(string $tool, array $input): array
    {
        return match ($tool) {
            'kunde_anlegen'   => $this->planKunde($input),
            'projekt_anlegen' => $this->planProjekt($input),
            default           => throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".'),
        };
    }

    public function uebernehmen(string $tool, array $input): Ausgefuehrt
    {
        return match ($tool) {
            'kunde_anlegen'   => $this->kundeAnlegen($input),
            'projekt_anlegen' => $this->projektAnlegen($input),
            default           => throw new BadToolInput('Unbekanntes Werkzeug "'.$tool.'".'),
        };
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     */
    private function planKunde(array $input): array
    {
        $kunde   = $this->kundeAus($input);
        $details = \array_filter([
            'Firma'    => $kunde->getCompanyName(),
            'E-Mail'   => $kunde->getEmail(),
            'Telefon'  => $kunde->getPhone(),
            'Mobil'    => $kunde->getMobile(),
            'Adresse'  => \trim(\implode(', ', \array_filter([
                \trim($kunde->getStreet().' '.$kunde->getHousenumber()),
                \trim($kunde->getPlz().' '.$kunde->getCity()),
                (string) $kunde->getCountry(),
            ]))),
            'Webseite' => $kunde->getWebsite(),
        ], static fn (?string $wert): bool => $wert !== null && \trim($wert) !== '');

        return $this->planned('kunde_anlegen', 'Kunde anlegen: „'.$kunde.'“', $details, $input);
    }

    /** @param array<string, mixed> $input */
    private function kundeAnlegen(array $input): Ausgefuehrt
    {
        $kunde = $this->kundeAus($input);

        $this->em->persist($kunde);
        $this->em->flush();

        return new Ausgefuehrt(
            'Kunde „'.$kunde.'“ angelegt.',
            $this->link('admin_customer_show', $kunde->getId()),
            (string) $kunde,
        );
    }

    /**
     * Der Kunde aus einem Werkzeugaufruf, noch nicht gespeichert – für die
     * Vorschau ebenso wie fürs Anlegen, damit beide dasselbe zeigen.
     *
     * @param array<string, mixed> $input
     */
    private function kundeAus(array $input): Customer
    {
        $text = static fn (string $feld): ?string => \trim((string) ($input[$feld] ?? '')) ?: null;

        $kunde = (new Customer())
            ->setFirstname($this->requireText($input, 'vorname'))
            ->setLastname($this->requireText($input, 'nachname'))
            ->setCompanyName($text('firma'))
            ->setEmail($this->requireText($input, 'email'))
            ->setPhone($text('telefon'))
            ->setMobile($text('mobil'))
            ->setStreet($text('strasse'))
            ->setHousenumber($text('hausnummer'))
            ->setPlz($text('plz'))
            ->setCity($text('ort'))
            ->setCountry($text('land'))
            ->setWebsite($text('webseite'));

        // Die Angaben kommen vom Sprachmodell: geprüft wird mit denselben
        // Regeln wie im Formular, damit nichts Unmögliches gespeichert wird.
        $fehler = $this->validator->validate($kunde);

        if (\count($fehler) > 0) {
            throw new BadToolInput((string) $fehler->get(0)->getMessage());
        }

        return $kunde;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Plan
     */
    private function planProjekt(array $input): array
    {
        $projekt = $this->projektAus($input);
        $details = \array_filter([
            'Kunde'        => (string) $projekt->getCustomer(),
            'Beschreibung' => \mb_strimwidth((string) $projekt->getDescription(), 0, 400, '…'),
        ], static fn (string $wert): bool => \trim($wert) !== '');

        return $this->planned('projekt_anlegen', 'Projekt anlegen: „'.$projekt->getName().'“', $details, $input);
    }

    /** @param array<string, mixed> $input */
    private function projektAnlegen(array $input): Ausgefuehrt
    {
        $projekt = $this->projektAus($input);

        $this->em->persist($projekt);
        $this->em->flush();

        return new Ausgefuehrt(
            'Projekt „'.$projekt->getName().'“ für '.$projekt->getCustomer().' angelegt.',
            $this->link('admin_project_show', $projekt->getId()),
            (string) $projekt->getName(),
        );
    }

    /** @param array<string, mixed> $input */
    private function projektAus(array $input): Project
    {
        $id    = (int) ($input['kunde_id'] ?? 0);
        $kunde = $id > 0 ? $this->customers->find($id) : null;

        if (!$kunde instanceof Customer) {
            throw new BadToolInput('Es gibt keinen Kunden mit der ID '.$id.'. Erst mit suchen die richtige ID holen.');
        }

        return (new Project())
            ->setCustomer($kunde)
            ->setName($this->requireText($input, 'name'))
            ->setDescription(\trim((string) ($input['beschreibung'] ?? '')) ?: null);
    }
}
