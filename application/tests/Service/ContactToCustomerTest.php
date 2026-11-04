<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContactToCustomer;
use App\Service\PortalContact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the portal's card becomes in the customer form.
 *
 * The three guesses are tested here and nowhere else: one name field into
 * two, one street line into street and number, a list of numbers into
 * "Telefon" and "Mobil". They are guesses, so the point of these tests is
 * which ones are promised to work.
 */
final class ContactToCustomerTest extends TestCase
{
    public function testAFullContactLandsInEveryField(): void
    {
        $customer = ContactToCustomer::convert(new PortalContact(
            id: 7,
            name: 'Max Muster',
            organisation: 'Musterfirma GmbH',
            emails: ['max@example.com', 'max@firma.de'],
            phones: ['05451 123456', '+49 170 1234567'],
            street: 'Musterweg 7',
            postalCode: '49477',
            city: 'Ibbenbüren',
            country: 'Deutschland',
            website: 'https://www.musterfirma.de',
            note: 'Kennt sich mit Dächern aus.',
            tags: ['Geschäftlich'],
        ));

        self::assertSame('Max', $customer->getFirstname());
        self::assertSame('Muster', $customer->getLastname());
        self::assertSame('Musterfirma GmbH', $customer->getCompanyName());
        self::assertSame('max@example.com', $customer->getEmail(), 'Die erste Adresse gilt.');
        self::assertSame('05451 123456', $customer->getPhone());
        self::assertSame('+49 170 1234567', $customer->getMobile());
        self::assertSame('Musterweg', $customer->getStreet());
        self::assertSame('7', $customer->getHousenumber());
        self::assertSame('49477', $customer->getPlz());
        self::assertSame('Ibbenbüren', $customer->getCity());
        self::assertSame('Deutschland', $customer->getCountry());
        self::assertSame('https://www.musterfirma.de', $customer->getWebsite());
        self::assertTrue($customer->isActive());
    }

    public function testWithoutMailAddressTheFieldStaysEmptyForTheForm(): void
    {
        $customer = ContactToCustomer::convert(new PortalContact(id: 1, name: 'Erika Beispiel'));

        self::assertNull($customer->getEmail());
        self::assertSame('Erika', $customer->getFirstname());
        self::assertSame('Beispiel', $customer->getLastname());
    }

    public function testNoteAndTagsDoNotComeAlong(): void
    {
        $customer = ContactToCustomer::convert(new PortalContact(
            id: 1,
            name: 'Max Muster',
            note: 'Nur fürs Adressbuch.',
            tags: ['Geschäftlich'],
        ));

        // A customer has no field for either – they stay in the portal.
        self::assertSame('Max', $customer->getFirstname());
        self::assertSame('Muster', $customer->getLastname());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function names(): iterable
    {
        yield 'Vor- und Nachname'      => ['Max Muster', 'Max', 'Muster'];
        yield 'mit Komma'              => ['Muster, Max', 'Max', 'Muster'];
        yield 'Komma ohne Vornamen'    => ['Muster,', '', 'Muster'];
        yield 'nur ein Wort'           => ['Rechnungsstelle', '', 'Rechnungsstelle'];
        yield 'zwei Vornamen'          => ['Anna Lena Schmidt', 'Anna Lena', 'Schmidt'];
        yield 'Adelspartikel'          => ['Max von Muster', 'Max', 'von Muster'];
        yield 'zwei Partikel'          => ['Anne van der Berg', 'Anne', 'van der Berg'];
        yield 'Titel bleibt im Vornamen' => ['Dr. Max Muster', 'Dr. Max', 'Muster'];
        yield 'Leerraum wird gestaucht' => ['  Max   Muster ', 'Max', 'Muster'];
        yield 'leer'                   => ['', '', ''];
        // "van" is the whole surname here, so it must not be eaten as a particle.
        yield 'Partikel als Nachname'  => ['Tom van', 'Tom', 'van'];
    }

    #[DataProvider('names')]
    public function testTheNameIsSplitAtTheLastWord(string $name, string $firstname, string $lastname): void
    {
        self::assertSame(['firstname' => $firstname, 'lastname' => $lastname], ContactToCustomer::splitName($name));
    }

    /** @return iterable<string, array{string|null, string|null, string|null}> */
    public static function streets(): iterable
    {
        yield 'Straße und Nummer'    => ['Musterweg 7', 'Musterweg', '7'];
        yield 'Nummer mit Buchstabe' => ['Musterweg 7a', 'Musterweg', '7a'];
        yield 'Nummernbereich'       => ['Musterweg 7-9', 'Musterweg', '7-9'];
        yield 'Nummer mit Schrägstrich' => ['Musterweg 7/2', 'Musterweg', '7/2'];
        yield 'mehrteilige Straße'   => ['Platz der Republik 1', 'Platz der Republik', '1'];
        yield 'abgekürzt'            => ['Hauptstr. 5', 'Hauptstr.', '5'];
        yield 'Komma vor der Nummer' => ['Musterweg, 7', 'Musterweg', '7'];
        // No number at the end: the line stays whole rather than being
        // guessed apart in the middle.
        yield 'Zahl im Namen'        => ['Straße des 17. Juni', 'Straße des 17. Juni', null];
        yield 'Stockwerk dahinter'   => ['Musterweg 7, 2. OG', 'Musterweg 7, 2. OG', null];
        yield 'ohne Nummer'          => ['Am Markt', 'Am Markt', null];
        yield 'Nummer vorn'          => ['7 Rue de la Paix', '7 Rue de la Paix', null];
        yield 'leer'                 => ['', null, null];
        yield 'nicht gesetzt'        => [null, null, null];
    }

    #[DataProvider('streets')]
    public function testTheHouseNumberIsTakenFromTheEnd(?string $line, ?string $street, ?string $number): void
    {
        self::assertSame(['street' => $street, 'number' => $number], ContactToCustomer::splitStreet($line));
    }

    /** @return iterable<string, array{list<string>, string|null, string|null}> */
    public static function phones(): iterable
    {
        yield 'Festnetz und Mobil' => [['05451 123456', '0170 1234567'], '05451 123456', '0170 1234567'];
        yield 'Mobil zuerst'       => [['0170 1234567', '05451 123456'], '05451 123456', '0170 1234567'];
        yield 'nur Festnetz'       => [['05451 123456'], '05451 123456', null];
        yield 'nur Mobil'          => [['0151 2345678'], null, '0151 2345678'];
        yield 'international'      => [['+49 170 1234567'], null, '+49 170 1234567'];
        yield 'mit 0049'           => [['0049 160 1234567'], null, '0049 160 1234567'];
        yield 'Klammern und Striche' => [['(0171) 234-5678'], null, '(0171) 234-5678'];
        // 018 is a service number, not a mobile one.
        yield 'Servicenummer'      => [['0180 5 123456'], '0180 5 123456', null];
        yield 'Ausland bleibt Telefon' => [['+31 20 1234567'], '+31 20 1234567', null];
        yield 'leer'               => [[], null, null];
        yield 'je der erste'       => [
            ['05451 1', '05451 2', '0170 1', '0170 2'],
            '05451 1',
            '0170 1',
        ];
    }

    /** @param list<string> $numbers */
    #[DataProvider('phones')]
    public function testNumbersAreSortedByPrefix(array $numbers, ?string $phone, ?string $mobile): void
    {
        self::assertSame($phone, ContactToCustomer::firstPhone($numbers, mobile: false), 'Telefon');
        self::assertSame($mobile, ContactToCustomer::firstPhone($numbers, mobile: true), 'Mobil');
    }
}
