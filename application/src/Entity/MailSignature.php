<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceTemplate;
use App\Repository\MailSignatureRepository;
use App\Service\HtmlToText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An e-mail signature placed under the text of an outgoing invoice mail.
 *
 * Signatures are shared by all admins, like the customers and invoices they
 * belong to. Each invoice carries the signature it goes out with
 * (Invoice::$signature); a signature can additionally be the default for one
 * letterhead ({@see InvoiceTemplate}), so that a Mirwena invoice is preset
 * with the Mirwena signature and an Achim Krämer invoice with his.
 *
 * Two kinds exist: plain text, or HTML with formatting, links and images as
 * known from Gmail or Spark. An HTML signature turns the mail into an HTML
 * mail (see MailBody); the text next to it stays what it is.
 */
#[ORM\Entity(repositoryClass: MailSignatureRepository::class)]
#[ORM\Table(name: 'mail_signature')]
class MailSignature
{
    final public const string FORMAT_TEXT = 'text';
    final public const string FORMAT_HTML = 'html';

    /** @var array<string, string> label => value, for form choices */
    final public const array FORMAT_CHOICES = [
        'Reiner Text'       => self::FORMAT_TEXT,
        'HTML (formatiert)' => self::FORMAT_HTML,
    ];

    /**
     * The separator line before a signature: two dashes and a space
     * (RFC 3676). Mail clients recognise where the signature starts and
     * leave it out when quoting.
     */
    final public const string DELIMITER = '-- ';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte einen Namen angeben, unter dem die Signatur in der Auswahl steht.')]
    #[Assert\Length(max: 120)]
    private ?string $label = null;

    /** The HTML source for HTML signatures, otherwise the plain text. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Bitte den Text der Signatur angeben.')]
    private ?string $text = null;

    #[ORM\Column(length: 10, options: ['default' => self::FORMAT_TEXT])]
    private string $format = self::FORMAT_TEXT;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    /** Letterhead whose new invoices are preset with this signature; null if it is not a default. */
    #[ORM\Column(length: 20, nullable: true, enumType: InvoiceTemplate::class)]
    private ?InvoiceTemplate $defaultForTemplate = null;

    public function __toString(): string
    {
        return (string) $this->label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Line breaks are normalised: the form submits CRLF, the mail text uses LF
     * like the rest of the message.
     */
    public function setText(?string $text): static
    {
        $this->text = $text === null ? null : \preg_replace('/\R/', "\n", $text);

        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function isHtml(): bool
    {
        return $this->format === self::FORMAT_HTML;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDefaultForTemplate(): ?InvoiceTemplate
    {
        return $this->defaultForTemplate;
    }

    public function setDefaultForTemplate(?InvoiceTemplate $template): static
    {
        $this->defaultForTemplate = $template;

        return $this;
    }

    /**
     * The signature as plain text, as it goes under the message: behind the
     * separator line "-- ". If the text already starts with one, it stays at
     * that one. For an HTML signature the text is extracted – this is the
     * version for the text part of the mail and for clients that show no HTML.
     */
    public function asBlock(): string
    {
        $text = \trim($this->isHtml() ? HtmlToText::convert((string) $this->text) : (string) $this->text);

        if (\preg_match('/^--\s*(\n|$)/', $text) === 1) {
            return $text;
        }

        return self::DELIMITER."\n".$text;
    }

    /** The HTML source – only for HTML signatures, otherwise null. */
    public function asHtml(): ?string
    {
        return $this->isHtml() ? \trim((string) $this->text) : null;
    }
}
