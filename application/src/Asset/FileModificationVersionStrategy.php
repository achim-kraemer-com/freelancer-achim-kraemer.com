<?php

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hängt den Änderungszeitpunkt der Datei als Version an (…/app.css?v=1756…).
 *
 * CSS und JavaScript werden ohne Build-Schritt ausgeliefert, und der Server
 * schickt dazu nur ein Last-Modified. Ohne Version zeigt ein Browser – Safari
 * auf dem Telefon besonders hartnäckig – nach einer Änderung weiter die
 * zwischengespeicherte Fassung: Das neue HTML trifft auf das alte CSS, und die
 * Seite sieht kaputt aus, ohne dass jemand etwas falsch gemacht hätte.
 *
 * Nach dem Vorbild des Portals (portal.achim-kraemer.com).
 */
final class FileModificationVersionStrategy implements VersionStrategyInterface
{
    /** @var array<string, string> */
    private array $versions = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        return $this->versions[$path] ??= $this->readVersion($path);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        if ($version === '') {
            return $path;
        }

        return $path.(\str_contains($path, '?') ? '&' : '?').'v='.$version;
    }

    private function readVersion(string $path): string
    {
        $file = $this->publicDir.'/'.\ltrim(\parse_url($path, \PHP_URL_PATH) ?: $path, '/');

        // Fehlt die Datei (etwa bei einer absoluten URL), bleibt der Pfad, wie er ist.
        $mtime = \is_file($file) ? \filemtime($file) : false;

        return $mtime === false ? '' : (string) $mtime;
    }
}
