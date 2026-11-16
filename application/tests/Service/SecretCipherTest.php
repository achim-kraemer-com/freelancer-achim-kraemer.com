<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SecretCipher;
use PHPUnit\Framework\TestCase;

/**
 * Woher der Schlüssel kommt, mit dem die API-Schlüssel der Sprachmodelle
 * verschlüsselt in der Datenbank liegen.
 *
 * Ein eigener APP_CRYPTO_KEY hat Vorrang; ohne ihn wird aus APP_SECRET einer
 * abgeleitet, damit ein frischer Server ohne Handgriff funktioniert. Nur wenn
 * auch APP_SECRET noch der oeffentliche Wert aus dem Repo ist, verweigert der
 * Cipher den Dienst.
 */
final class SecretCipherTest extends TestCase
{
    private const EIGENER = 'Zm9vYmFyMTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3A=';

    public function testMitEigenemSchluesselHinUndZurueck(): void
    {
        $cipher = new SecretCipher(self::EIGENER, 'egal', 'prod');
        $kryptisch = $cipher->encrypt('sk-geheim-123');

        self::assertNotNull($kryptisch);
        self::assertNotSame('sk-geheim-123', $kryptisch);
        self::assertSame('sk-geheim-123', $cipher->decrypt($kryptisch));
    }

    /** Ohne eigenen Schlüssel trägt APP_SECRET die Verschlüsselung. */
    public function testOhneEigenenSchluesselTraegtAppSecret(): void
    {
        $cipher = new SecretCipher('', 'ein-echtes-produktionsgeheimnis', 'prod');

        self::assertSame('sk-geheim-123', $cipher->decrypt((string) $cipher->encrypt('sk-geheim-123')));
    }

    /** Der öffentliche Wert aus der .env zählt wie "nicht gesetzt". */
    public function testDerEntwicklungswertZaehltAlsNichtGesetzt(): void
    {
        $ausEnv  = new SecretCipher('nur-fuer-lokale-entwicklung-nicht-produktiv', 'geheim', 'prod');
        $ohne    = new SecretCipher('', 'geheim', 'prod');

        // Beide leiten aus demselben APP_SECRET ab, also liest einer des anderen.
        self::assertSame('sk-1', $ohne->decrypt((string) $ausEnv->encrypt('sk-1')));
    }

    /** Ein anderes APP_SECRET liest die alten Werte nicht mehr. */
    public function testNachRotiertemAppSecretBleibtDerWertUnlesbar(): void
    {
        $alt = new SecretCipher('', 'geheim-alt', 'prod');
        $neu = new SecretCipher('', 'geheim-neu', 'prod');

        self::assertNull($neu->decrypt((string) $alt->encrypt('sk-1')));
    }

    /** Ein eigener Schlüssel hat Vorrang vor der Ableitung. */
    public function testEigenerSchluesselSchlaegtDieAbleitung(): void
    {
        $eigen      = new SecretCipher(self::EIGENER, 'geheim', 'prod');
        $abgeleitet = new SecretCipher('', 'geheim', 'prod');

        self::assertNull($eigen->decrypt((string) $abgeleitet->encrypt('sk-1')));
    }

    /** Stehen beide noch auf dem Wert aus dem Repo, ist in prod Schluss. */
    public function testOhneJedesGeheimnisInProduktionFehler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/APP_CRYPTO_KEY.*APP_SECRET/s');

        new SecretCipher('nur-fuer-lokale-entwicklung-nicht-produktiv', 'ChangeMe', 'prod');
    }

    /** Lokal und in den Tests darf beides so bleiben – dort liegt nichts Echtes. */
    public function testInDevUndTestGehtEsAuchOhneGeheimnis(): void
    {
        foreach (['dev', 'test'] as $umgebung) {
            $cipher = new SecretCipher('nur-fuer-lokale-entwicklung-nicht-produktiv', 'ChangeMe', $umgebung);

            self::assertSame('sk-1', $cipher->decrypt((string) $cipher->encrypt('sk-1')));
        }
    }

    public function testLeeresUndKaputtesBleibtNull(): void
    {
        $cipher = new SecretCipher(self::EIGENER, 'geheim', 'prod');

        self::assertNull($cipher->encrypt(null));
        self::assertNull($cipher->encrypt(''));
        self::assertNull($cipher->decrypt(null));
        self::assertNull($cipher->decrypt(''));
        self::assertNull($cipher->decrypt('kein base64 und erst recht kein Geheimtext'));
    }
}
