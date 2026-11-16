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

    private readonly string $key;

    public function __construct(
        #[Autowire('%env(APP_CRYPTO_KEY)%')] string $key,
        #[Autowire('%kernel.environment%')] string $environment,
    ) {
        $key = \trim($key);

        if ($key === '') {
            throw new \LogicException(
                'APP_CRYPTO_KEY ist nicht gesetzt. Ohne diesen Schlüssel lassen sich die '
                .'Schlüssel der Sprachmodelle weder speichern noch lesen. Einen erzeugen mit: '
                ."php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'",
            );
        }

        // Ein bekannter Entwicklungsschlüssel in der Produktion wäre so gut wie
        // gar keine Verschlüsselung – jeder mit Zugriff auf die Datenbank könnte
        // die hinterlegten Schlüssel im Klartext lesen. In den Tests wird nichts
        // Echtes verschlüsselt; dort darf der Wert aus der .env stehen bleiben.
        if (!\in_array($environment, ['dev', 'test'], true) && \in_array($key, self::UNSAFE_KEYS, true)) {
            throw new \LogicException(
                'APP_CRYPTO_KEY steht noch auf dem Entwicklungswert. Dieser Wert ist '
                .'öffentlich bekannt und darf außerhalb der lokalen Entwicklung nicht '
                ."verwendet werden. Einen eigenen erzeugen mit: "
                ."php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'",
            );
        }

        $raw = \base64_decode($key, true);

        if ($raw === false || $raw === '') {
            $raw = $key;
        }

        // Beliebig langes Schlüsselmaterial auf die benötigten 32 Byte verdichten.
        $this->key = \strlen($raw) === \SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ? $raw
            : \sodium_crypto_generichash($raw, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
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
