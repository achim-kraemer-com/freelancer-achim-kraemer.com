<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\AssistentExtension;
use PHPUnit\Framework\TestCase;

/**
 * Die Antworten des Assistenten als HTML: Links nur auf eigene Pfade, alles
 * andere bleibt Text.
 */
final class AssistentExtensionTest extends TestCase
{
    public function testEinPfadWirdZumLink(): void
    {
        self::assertSame(
            'Steht in <a href="/admin/project/12">Webshop-Relaunch</a>.',
            $this->text('Steht in [Webshop-Relaunch](/admin/project/12).'),
        );
    }

    /** Ein Link nach draußen bleibt stehen, wie er ist – als Text. */
    public function testEineFremdeAdresseWirdKeinLink(): void
    {
        $html = $this->text('Lies [das hier](https://example.com/phish).');

        self::assertStringNotContainsString('<a', $html);
        self::assertStringContainsString('[das hier](https://example.com/phish)', $html);
    }

    /** „//host" liest der Browser als anderen Server – also kein Link. */
    public function testEineAdresseOhneSchemaWirdKeinLink(): void
    {
        self::assertStringNotContainsString('<a', $this->text('[weg](//example.com/phish)'));
        self::assertStringNotContainsString('<a', $this->text('[weg](/\\example.com)'));
    }

    public function testHtmlImTextWirdEntschaerft(): void
    {
        $html = $this->text('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testZeilenumbruecheBleiben(): void
    {
        self::assertStringContainsString('<br />', $this->text("erste Zeile\nzweite Zeile"));
    }

    private function text(string $text): string
    {
        return (new AssistentExtension())->text($text);
    }
}
