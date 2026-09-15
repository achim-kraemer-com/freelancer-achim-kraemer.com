<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HtmlToText;
use PHPUnit\Framework\TestCase;

/**
 * The text that remains of an HTML signature – for the text part of the
 * mail and for mail clients that show no HTML.
 */
final class HtmlToTextTest extends TestCase
{
    public function testBlockElementsBecomeLines(): void
    {
        $html = '<div><b>Achim Krämer</b></div><div>Softwareentwicklung</div><p>Berlin<br>Deutschland</p>';

        self::assertSame("Achim Krämer\nSoftwareentwicklung\n\nBerlin\nDeutschland", HtmlToText::convert($html));
    }

    public function testLinkTakesItsAddressAlong(): void
    {
        self::assertSame(
            'Meine Seite (https://achim-kraemer.com)',
            HtmlToText::convert('<a href="https://achim-kraemer.com">Meine Seite</a>'),
        );
    }

    /** If the text shows the address already, the parenthesis would only repeat it. */
    public function testVisibleAddressIsNotRepeated(): void
    {
        self::assertSame('achim-kraemer.com', HtmlToText::convert('<a href="https://achim-kraemer.com/">achim-kraemer.com</a>'));
        self::assertSame('www.achim-kraemer.com', HtmlToText::convert('<a href="https://achim-kraemer.com">www.achim-kraemer.com</a>'));
        self::assertSame('achim@achim-kraemer.com', HtmlToText::convert('<a href="mailto:achim@achim-kraemer.com">achim@achim-kraemer.com</a>'));
        self::assertSame('+49 30 123 45', HtmlToText::convert('<a href="tel:+493012345">+49 30 123 45</a>'));
    }

    public function testLinkedIconLendsItsName(): void
    {
        self::assertSame(
            'facebook (https://facebook.com/achim)',
            HtmlToText::convert('<a href="https://facebook.com/achim"><img src="fb.png" alt="facebook"></a>'),
        );
    }

    /** Icons in front of phone number and address say nothing as a word. */
    public function testIconsWithoutLinkVanish(): void
    {
        $html = '<table><tr><td><img src="phone.png" alt="phone"></td><td>+49 30 123 45</td></tr>'
            .'<tr><td><img src="mail.png" alt="email"></td><td>achim@achim-kraemer.com</td></tr></table>';

        self::assertSame("+49 30 123 45\nachim@achim-kraemer.com", HtmlToText::convert($html));
    }

    public function testListsGetDashes(): void
    {
        self::assertSame("- Beratung\n- Entwicklung", HtmlToText::convert('<ul><li>Beratung</li><li>Entwicklung</li></ul>'));
    }

    public function testInvisibleContentAndEntitiesAreResolved(): void
    {
        $html = '<style>b{color:red}</style><!-- Kommentar --><span>Kr&auml;mer &amp; Co&nbsp;GmbH</span><script>alert(1)</script>';

        self::assertSame('Krämer & Co GmbH', HtmlToText::convert($html));
    }

    /** The structure a signature generator delivers: table, icons, links. */
    public function testGeneratedSignatureBecomesReadableText(): void
    {
        $html = <<<'HTML'
            <div style="font-family: Arial">
                <div style="margin-bottom:10px;">Viele Grüße,</div>
                <table><tr>
                    <td><img src="https://sig.example/logo.png" alt="logo" width="100"></td>
                    <td><div style="font: bold 15px Arial">Achim Krämer</div><div><span>Softwareentwickler</span></div></td>
                    <td><table>
                        <tr><td><img src="https://sig.example/phone.png" alt="phone"></td>
                            <td><a href="tel:+49301234" style="color:#000">+49 30 1234</a></td></tr>
                        <tr><td><img src="https://sig.example/globe.png" alt="website"></td>
                            <td><a href="https://achim-kraemer.com" target="_blank">achim-kraemer.com</a></td></tr>
                        <tr><td colspan="2">Besuche mich auf:
                            <a href="https://github.com/achim"><img src="https://sig.example/github.png" alt="github"></a></td></tr>
                    </table></td>
                </tr></table>
            </div>
            HTML;

        self::assertSame(
            "Viele Grüße,\nAchim Krämer\nSoftwareentwickler\n+49 30 1234\nachim-kraemer.com\nBesuche mich auf: github (https://github.com/achim)",
            HtmlToText::convert($html),
        );
    }
}
