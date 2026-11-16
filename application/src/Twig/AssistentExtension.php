<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Die Antworten des Assistenten als HTML.
 *
 * Das Modell verlinkt Einträge, die es gefunden hat, als Markdown-Link mit
 * dem Pfad aus dem Werkzeugergebnis – [Webshop-Relaunch](/admin/project/12).
 * Daraus werden Links, aber nur auf Pfade der Anwendung selbst: Die Antwort
 * gibt Text aus der Datenbank wieder, und ein Link nach draußen darin wäre
 * eine Einladung, ihm zu glauben. Alles andere bleibt Text.
 */
final class AssistentExtension
{
    #[AsTwigFilter('assistent_text', isSafe: ['html'])]
    public function text(string $text): string
    {
        $sicher = \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        // Nach dem Maskieren gesucht: Klammern und Schrägstriche bleiben dabei
        // stehen, ein & im Pfad steht schon als &amp; da – wie im Attribut nötig.
        $mitLinks = (string) \preg_replace_callback(
            // Kein „//" und kein „/\" am Anfang: Beides lesen Browser als Adresse
            // eines anderen Servers.
            '~\[([^\]\n]{1,200})\]\((/(?![/\\\\])[^\s)\\\\]*)\)~u',
            static fn (array $t): string => '<a href="'.$t[2].'">'.$t[1].'</a>',
            $sicher,
        );

        return \nl2br($mitLinks);
    }
}
