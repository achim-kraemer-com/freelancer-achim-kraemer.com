<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One contact as the portal's address book hands it over.
 *
 * A plain carrier, not an entity: nothing of this is stored here. It only
 * travels from the JSON of portal.achim-kraemer.com to the picker and from
 * there into a fresh customer (ContactToCustomer).
 *
 * The shape follows the portal's card, not this app's customer: one name
 * field, a list of mail addresses and a list of phone numbers, street and
 * house number in the same line. Splitting that up is the job of the
 * mapping, where it can be looked at and tested on its own.
 */
final readonly class PortalContact
{
    /**
     * @param list<string> $emails
     * @param list<string> $phones
     * @param list<string> $tags
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $organisation = null,
        public array $emails = [],
        public array $phones = [],
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $website = null,
        public ?string $note = null,
        public array $tags = [],
    ) {
    }

    /**
     * Reads one row of the portal's answer.
     *
     * Every field is taken as it comes and only given a type – what is
     * missing stays empty. The portal is the authority on its own address
     * book; a stricter check here would only turn a renamed field into an
     * error page instead of an empty line.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            name: self::text($row['name'] ?? null) ?? '',
            organisation: self::text($row['organisation'] ?? null),
            emails: self::strings($row['emails'] ?? null),
            phones: self::strings($row['phones'] ?? null),
            street: self::text($row['street'] ?? null),
            postalCode: self::text($row['postalCode'] ?? null),
            city: self::text($row['city'] ?? null),
            country: self::text($row['country'] ?? null),
            website: self::text($row['website'] ?? null),
            note: self::text($row['note'] ?? null),
            tags: self::strings($row['tags'] ?? null),
        );
    }

    /** The address one would write to – the portal puts it first. */
    public function primaryEmail(): ?string
    {
        return $this->emails[0] ?? null;
    }

    /** What the picker shows as the heading of a row. */
    public function label(): string
    {
        return $this->organisation !== null ? $this->organisation.' ('.$this->name.')' : $this->name;
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = \trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = self::text($item);

            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }
}
