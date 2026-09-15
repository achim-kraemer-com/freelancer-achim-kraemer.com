<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailSignature;

/**
 * The content of an outgoing mail: the text and, below it, the chosen
 * signature – the same arrangement as in the portal's mail client.
 *
 * Mails are written as plain text. Only an HTML signature turns one into an
 * HTML mail: then there is an additional HTML part in which the text is
 * carried over to HTML and the signature stands unchanged. The text part is
 * always there – for mail clients that show no HTML, and because it is what
 * remains when the recipient quotes the message.
 */
final class MailBody
{
    public function __construct(
        private readonly string $text,
        private readonly ?MailSignature $signature = null,
    ) {
    }

    public function isHtml(): bool
    {
        return $this->signature?->isHtml() === true;
    }

    /** Text and signature block, separated by a blank line – whichever of them is there. */
    public function text(): string
    {
        $parts = [
            \trim($this->text),
            $this->signature?->asBlock() ?? '',
        ];

        return \implode("\n\n", \array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** The HTML part – only with an HTML signature, otherwise null and the mail stays plain text. */
    public function html(): ?string
    {
        if (!$this->isHtml()) {
            return null;
        }

        $blocks = [];

        if (\trim($this->text) !== '') {
            $blocks[] = '<div>'.self::escape(\trim($this->text)).'</div>';
        }

        $blocks[] = '<div class="signature">'.$this->signature?->asHtml().'</div>';

        // Font and colour stated explicitly: without them every mail client
        // picks its own, and the HTML version would look unlike the text one.
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;'
            .'font-size:14px;line-height:1.5;color:#111111">'
            .\implode('<br>', $blocks)
            .'</div>';
    }

    /** Text to HTML: special characters escaped, line breaks as <br>. */
    private static function escape(string $text): string
    {
        return \nl2br(\htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
