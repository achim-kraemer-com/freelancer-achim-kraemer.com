<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Was eine bestätigte Aktion getan hat: der Satz dazu und, wo es danach noch
 * etwas zu sehen gibt, der Link dorthin – auf den neuen Kunden, das angelegte
 * Projekt, die erfasste Zeit.
 */
final readonly class Ausgefuehrt
{
    public function __construct(
        public string $text,
        public ?string $link = null,
        public ?string $titel = null,
    ) {
    }

    /** @return array{text: string, link: ?string, titel: ?string} */
    public function alsArray(): array
    {
        return ['text' => $this->text, 'link' => $this->link, 'titel' => $this->titel];
    }
}
