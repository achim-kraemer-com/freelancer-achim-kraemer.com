<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;

/**
 * Turns a contact from the portal into a customer for this app.
 *
 * The two differ in three places, and each needs a guess:
 *
 *  - the portal has one name field, a customer has first and last name;
 *  - the portal has street and house number in one line, a customer has two
 *    fields;
 *  - the portal has a list of phone numbers without a kind, a customer has
 *    "Telefon" and "Mobil".
 *
 * The guesses are good enough to save typing and wrong often enough that
 * nobody should save them unseen – which is why this fills a form for review
 * instead of writing to the database (CustomerController::new).
 */
final class ContactToCustomer
{
    /**
     * Parts of a surname that stand in front of it.
     *
     * Without them "Anne van der Berg" would end up as "Anne van der" with
     * the surname "Berg". The list is short on purpose: these are the ones
     * that actually turn up in a German address book.
     */
    private const array NAME_PARTICLES = [
        'von', 'vom', 'van', 'de', 'del', 'della', 'der', 'den', 'di', 'da',
        'du', 'la', 'le', 'ten', 'ter', 'zu', 'zum', 'zur', 'af', 'av',
    ];

    /**
     * A house number at the end of the street line: digits, maybe a letter,
     * maybe a range – "7", "7a", "7-9", "7/2".
     */
    private const string HOUSE_NUMBER = '/^(?<street>.+?)[\s,]+(?<number>\d+\s*[a-zA-Z]?(?:\s*[-\/]\s*\d+\s*[a-zA-Z]?)?)$/u';

    /** A customer, filled from the contact – not yet persisted. */
    public static function convert(PortalContact $contact): Customer
    {
        $customer = new Customer();
        $name     = self::splitName($contact->name);
        $address  = self::splitStreet($contact->street);

        $customer
            ->setFirstname($name['firstname'])
            ->setLastname($name['lastname'])
            ->setCompanyName($contact->organisation)
            ->setPhone(self::firstPhone($contact->phones, mobile: false))
            ->setMobile(self::firstPhone($contact->phones, mobile: true))
            ->setStreet($address['street'])
            ->setHousenumber($address['number'])
            ->setPlz($contact->postalCode)
            ->setCity($contact->city)
            ->setCountry($contact->country)
            ->setWebsite($contact->website)
            ->setIsActive(true);

        // A customer needs a mail address, a contact does not. Where the
        // card has none the field stays unset – the setter takes no null –
        // and the form asks for it.
        $email = $contact->primaryEmail();

        if ($email !== null) {
            $customer->setEmail($email);
        }

        return $customer;
    }

    /**
     * Splits a display name into first and last name.
     *
     * "Muster, Max" is read as surname first – that is what the comma means.
     * Otherwise the last word is the surname, together with any particle in
     * front of it. A name of one word becomes the surname: it is more often
     * a company contact ("Rechnungsstelle") than a first name, and the empty
     * first name makes the form ask.
     *
     * @return array{firstname: string, lastname: string}
     */
    public static function splitName(string $name): array
    {
        $name = \trim((string) \preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            return ['firstname' => '', 'lastname' => ''];
        }

        if (\str_contains($name, ',')) {
            [$lastname, $firstname] = \array_pad(\explode(',', $name, 2), 2, '');

            return ['firstname' => \trim($firstname), 'lastname' => \trim($lastname)];
        }

        $words = \explode(' ', $name);

        if (\count($words) === 1) {
            return ['firstname' => '', 'lastname' => $name];
        }

        // From the back: the surname, plus every particle standing before it.
        $start = \count($words) - 1;

        while ($start > 1 && \in_array(\mb_strtolower($words[$start - 1]), self::NAME_PARTICLES, true)) {
            --$start;
        }

        return [
            'firstname' => \implode(' ', \array_slice($words, 0, $start)),
            'lastname'  => \implode(' ', \array_slice($words, $start)),
        ];
    }

    /**
     * Splits "Musterweg 7" into street and house number.
     *
     * Only a number at the end counts. "Straße des 17. Juni" has no number
     * at the end and stays whole, and so does anything with a floor behind
     * the number ("Musterweg 7, 2. OG") – half a guess in the street field
     * is worse than the line as it was written.
     *
     * @return array{street: string|null, number: string|null}
     */
    public static function splitStreet(?string $street): array
    {
        $street = $street === null ? '' : \trim((string) \preg_replace('/\s+/u', ' ', $street));

        if ($street === '') {
            return ['street' => null, 'number' => null];
        }

        if (\preg_match(self::HOUSE_NUMBER, $street, $matches) !== 1) {
            return ['street' => $street, 'number' => null];
        }

        $number = \trim((string) \preg_replace('/\s+/u', '', $matches['number']));
        $rest   = \trim($matches['street']);

        // The house number has twenty characters in the database, the street
        // 255. What does not fit stays in one line rather than being cut.
        if ($rest === '' || \mb_strlen($number) > 20) {
            return ['street' => $street, 'number' => null];
        }

        return ['street' => $rest, 'number' => $number];
    }

    /**
     * The first mobile or the first landline number from the list.
     *
     * The portal stores numbers without a kind, so the prefix has to say it:
     * in Germany mobile numbers start with 015, 016 or 017. A foreign number
     * that does not say so counts as a landline – it then stands in
     * "Telefon", where it is at least not lost.
     *
     * @param list<string> $phones
     */
    public static function firstPhone(array $phones, bool $mobile): ?string
    {
        foreach ($phones as $phone) {
            if (self::isMobile($phone) === $mobile) {
                return $phone;
            }
        }

        return null;
    }

    private static function isMobile(string $phone): bool
    {
        $digits = (string) \preg_replace('/[^0-9+]/', '', $phone);
        $digits = (string) \preg_replace('/^(?:\+49|0049)/', '0', $digits);

        return \preg_match('/^01[5-7]/', $digits) === 1;
    }
}
