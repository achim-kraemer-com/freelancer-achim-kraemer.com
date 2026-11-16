<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetrische Verschlüsselung für fremde Zugangsdaten (XSalsa20-Poly1305):
 * hier die Schlüssel der angeschlossenen Sprachmodelle.
 *
 * Ein solcher Schlüssel muss im Klartext an den Anbieter gehen, darf also
 * nicht gehasht werden. Er liegt deshalb verschlüsselt in der Datenbank – wer
 * nur die Datenbank in die Hände bekommt, kann damit nichts anfangen.
 *
 * Das Schlüsselmaterial kommt aus APP_CRYPTO_KEY. Steht dort nichts oder nur
 * der öffentlich bekannte Wert aus der versionierten .env, wird es aus
 * APP_SECRET abgeleitet – das ist auf jedem Server ohnehin eigen und geheim.
 * So laufen Modelle und Assistent nach einem Deploy sofort, ohne dass jemand
 * erst eine Zeile in die .env.local schreibt; wer den Schlüssel trennen will,
 * setzt APP_CRYPTO_KEY und hat Vorrang.
 *
 * Beides gilt: Wird das benutzte Material getauscht (eigener Schlüssel gesetzt
 * oder APP_SECRET rotiert), sind die gespeicherten Schlüssel nicht mehr lesbar
 * und müssen unter Modelle neu eingetragen werden.
 */
final class SecretCipher
{
    /**
     * Werte, die nur zur lokalen Entwicklung taugen. Sie stehen in der
     * versionierten .env und sind damit öffentlich bekannt.
     *
     * @var list<string>
     */
    private const UNSAFE_KEYS = [
        'nur-fuer-lokale-entwicklung-nicht-produktiv',
        'ChangeMe',
    ];

    /**
     * Damit aus APP_SECRET ein eigener Schlüssel wird und nicht derselbe Wert,
     * mit dem Symfony Formulare und „Angemeldet bleiben" signiert.
     */
    private const ABLEITUNG = 'app.assistant.model.key';

    private readonly string $key;

    public function __construct(
        #[Autowire('%env(APP_CRYPTO_KEY)%')] string $key,
        #[Autowire('%kernel.secret%')] string $appSecret,
        #[Autowire('%kernel.environment%')] string $environment,
    ) {
        $key       = \trim($key);
        $appSecret = \trim($appSecret);

        // Ein eigener Schlüssel hat Vorrang; sonst wird aus APP_SECRET einer
        // abgeleitet.
        if ($key !== '' && !\in_array($key, self::UNSAFE_KEYS, true)) {
            $raw = \base64_decode($key, true);

            // Wer keinen base64-Wert einträgt, bekommt seinen Text als Material.
            if ($raw === false || $raw === '') {
                $raw = $key;
            }

            // Beliebig langes Schlüsselmaterial auf die benötigten 32 Byte verdichten.
            $this->key = \strlen($raw) === \SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                ? $raw
                : \sodium_crypto_generichash($raw, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

            return;
        }

        // Ein öffentlich bekannter Wert in der Produktion wäre so gut wie gar
        // keine Verschlüsselung – jeder mit Zugriff auf die Datenbank könnte
        // die hinterlegten Schlüssel im Klartext lesen. Steht auch APP_SECRET
        // noch auf dem Wert aus dem Repo, hilft die Ableitung nicht. In dev und
        // test wird nichts Echtes verschlüsselt; dort darf beides so bleiben.
        if (!\in_array($environment, ['dev', 'test'], true)
            && (\in_array($appSecret, self::UNSAFE_KEYS, true) || $appSecret === '')
        ) {
            throw new \LogicException(
                'Weder APP_CRYPTO_KEY noch APP_SECRET sind gesetzt – beide stehen noch auf '
                .'dem Wert aus der versionierten .env, und der ist öffentlich bekannt. '
                .'Die Produktionswerte gehören in die .env.local; einen eigenen Schlüssel '
                ."erzeugt php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'",
            );
        }

        $this->key = \sodium_crypto_generichash($appSecret, self::ABLEITUNG, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return \base64_encode($nonce.\sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        $raw = \base64_decode($cipher, true);

        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce   = \substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = \substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain   = \sodium_crypto_secretbox_open($payload, $nonce, $this->key);

        return $plain === false ? null : $plain;
    }
}
