<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MailSignature;
use PHPUnit\Framework\TestCase;

/**
 * How a signature goes under the mail: behind the separator line "-- " that
 * mail clients recognise – and never with two of them.
 */
final class MailSignatureTest extends TestCase
{
    public function testBlockStartsWithTheDelimiter(): void
    {
        $signature = (new MailSignature())->setText("Achim Krämer\nachim-kraemer.com");

        self::assertSame("-- \nAchim Krämer\nachim-kraemer.com", $signature->asBlock());
    }

    public function testExistingDelimiterIsNotDoubled(): void
    {
        self::assertSame("--\nAchim Krämer", (new MailSignature())->setText("--\nAchim Krämer")->asBlock());
        self::assertSame("-- \nAchim Krämer", (new MailSignature())->setText("-- \nAchim Krämer")->asBlock());
    }

    /** Two dashes followed by text are not a delimiter but text. */
    public function testDashesFollowedByTextAreNoDelimiter(): void
    {
        self::assertSame("-- \n-- Achim --", (new MailSignature())->setText('-- Achim --')->asBlock());
    }

    /** Of an HTML signature the text block keeps the text – behind the delimiter. */
    public function testHtmlSignatureYieldsItsTextAsBlock(): void
    {
        $signature = (new MailSignature())
            ->setFormat(MailSignature::FORMAT_HTML)
            ->setText('<div><b>Achim Krämer</b></div><div><a href="https://achim-kraemer.com">Webseite</a></div>');

        self::assertTrue($signature->isHtml());
        self::assertSame("-- \nAchim Krämer\nWebseite (https://achim-kraemer.com)", $signature->asBlock());
        self::assertSame('<div><b>Achim Krämer</b></div><div><a href="https://achim-kraemer.com">Webseite</a></div>', $signature->asHtml());
    }

    public function testTextSignatureHasNoHtml(): void
    {
        self::assertNull((new MailSignature())->setText('Achim Krämer')->asHtml());
    }

    /** The form submits CRLF line breaks; stored is LF throughout. */
    public function testLineBreaksAreNormalised(): void
    {
        $signature = (new MailSignature())->setText("Achim Krämer\r\nTelefon 0123\r\n");

        self::assertSame("Achim Krämer\nTelefon 0123\n", $signature->getText());
        self::assertSame("-- \nAchim Krämer\nTelefon 0123", $signature->asBlock());
    }
}
