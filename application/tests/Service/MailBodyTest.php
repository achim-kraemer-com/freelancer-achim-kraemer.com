<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MailSignature;
use App\Service\MailBody;
use PHPUnit\Framework\TestCase;

/**
 * How text and signature become the mail – and when it is an HTML mail.
 */
final class MailBodyTest extends TestCase
{
    public function testWithoutSignatureItStaysText(): void
    {
        $body = new MailBody("Guten Tag,\n\nanbei die Rechnung.\n\n");

        self::assertSame("Guten Tag,\n\nanbei die Rechnung.", $body->text());
        self::assertNull($body->html());
        self::assertFalse($body->isHtml());
    }

    public function testTextSignatureFollowsTheText(): void
    {
        $body = new MailBody('Mit freundlichen Grüßen', (new MailSignature())->setText('Achim Krämer'));

        self::assertSame("Mit freundlichen Grüßen\n\n-- \nAchim Krämer", $body->text());
        self::assertNull($body->html(), 'A text signature makes no HTML mail.');
    }

    public function testWithoutTextTheMailStartsWithTheSignature(): void
    {
        $body = new MailBody('', (new MailSignature())->setText('Achim Krämer'));

        self::assertSame("-- \nAchim Krämer", $body->text());
    }

    public function testHtmlSignatureMakesAnHtmlMailWithTextVersion(): void
    {
        $signature = (new MailSignature())
            ->setFormat(MailSignature::FORMAT_HTML)
            ->setText('<div><b>Achim Krämer</b><br><a href="https://achim-kraemer.com">achim-kraemer.com</a></div>');

        $body = new MailBody("Guten Tag,\n\nRechnung <1> & Co.", $signature);

        self::assertTrue($body->isHtml());
        self::assertSame("Guten Tag,\n\nRechnung <1> & Co.\n\n-- \nAchim Krämer\nachim-kraemer.com", $body->text());

        $html = (string) $body->html();

        // The own text escaped, with line breaks; the signature unchanged.
        self::assertStringContainsString('Guten Tag,<br>', $html);
        self::assertStringContainsString('Rechnung &lt;1&gt; &amp; Co.', $html);
        self::assertStringContainsString('<div class="signature"><div><b>Achim Krämer</b>', $html);
        self::assertStringNotContainsString('-- ', $html, 'In the HTML part the signature stands without the delimiter.');
        self::assertLessThan(\strpos($html, 'class="signature"'), \strpos($html, 'Guten Tag'));
    }
}
