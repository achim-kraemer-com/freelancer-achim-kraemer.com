<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Extracts the plain text from HTML – for the text part of a mail with an
 * HTML signature.
 *
 * Not a complete renderer, just what a signature needs: block elements become
 * lines, paragraphs get a blank line, list items a dash, the cells of a row
 * stand side by side. Links take their address along unless the text shows
 * it anyway. Images vanish – the icons in front of phone number and address
 * would say nothing as a word ("phone", "logo"); only a linked image lends
 * its alt text as the name of the link.
 */
final class HtmlToText
{
    /** Elements that form a line of their own. */
    private const array BLOCKS = [
        'div', 'tr', 'table', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'section', 'article', 'header', 'footer', 'hr', 'address',
    ];

    public static function convert(string $html): string
    {
        if (\trim($html) === '') {
            return '';
        }

        $document                      = new \DOMDocument();
        $document->strictErrorChecking = false;
        $previous                      = \libxml_use_internal_errors(true);

        // Without the meta line DOMDocument reads the content as Latin-1.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );

        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;

        if ($body === null) {
            return \trim(\html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }

        $out = '';
        self::walk($body, $out);

        return self::tidy($out);
    }

    private static function walk(\DOMNode $node, string &$out): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                // Whitespace counts as in the browser: any amount is one space.
                $out .= (string) \preg_replace('/\s+/', ' ', $child->nodeValue ?? '');

                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = \strtolower($child->tagName);

            if (\in_array($tag, ['script', 'style', 'head', 'title', 'img'], true)) {
                continue;
            }

            if ($tag === 'br') {
                $out .= "\n";
            } elseif ($tag === 'a') {
                $out .= self::link($child);
            } elseif ($tag === 'li') {
                self::newline($out);
                $out .= '- ';
                self::walk($child, $out);
                self::newline($out);
            } elseif ($tag === 'td' || $tag === 'th') {
                self::walk($child, $out);
                $out .= ' ';
            } elseif ($tag === 'p') {
                self::blankLine($out);
                self::walk($child, $out);
                self::blankLine($out);
            } elseif (\in_array($tag, self::BLOCKS, true)) {
                self::newline($out);
                self::walk($child, $out);
                self::newline($out);
            } else {
                self::walk($child, $out);
            }
        }
    }

    /** Starts a new line – unless one starts anyway. */
    private static function newline(string &$out): void
    {
        $out = \rtrim($out, ' ');

        if ($out !== '' && !\str_ends_with($out, "\n")) {
            $out .= "\n";
        }
    }

    /** A paragraph stands free: a blank line before and after – but only one. */
    private static function blankLine(string &$out): void
    {
        self::newline($out);

        if ($out !== '' && !\str_ends_with($out, "\n\n")) {
            $out .= "\n";
        }
    }

    /**
     * A link takes its address along in parentheses – unless the text is the
     * address already ("achim-kraemer.com" on https://achim-kraemer.com), or
     * the target is nothing one could type. If the link consists of an image
     * only, its alt text gives the name ("facebook").
     */
    private static function link(\DOMElement $a): string
    {
        $href  = \trim($a->getAttribute('href'));
        $inner = '';
        self::walk($a, $inner);
        $text = \trim((string) \preg_replace('/\s+/', ' ', $inner));

        if ($text === '') {
            foreach ($a->getElementsByTagName('img') as $img) {
                $text = \trim($img->getAttribute('alt'));

                if ($text !== '') {
                    break;
                }
            }

            $inner = $text;
        }

        if (\preg_match('#^(https?://|mailto:|tel:)#i', $href) !== 1 || $text === '') {
            return $inner;
        }

        $target = (string) \preg_replace('#^(mailto|tel):#i', '', $href);

        if (self::normalize($target) === self::normalize($text)) {
            return $inner;
        }

        return $inner.' ('.$target.')';
    }

    /** Scheme, www. and a trailing slash do not count; neither do spaces and dashes in phone numbers. */
    private static function normalize(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string) \preg_replace('#^(https?://)?(www\.)?#', '', $value);
        $value = (string) \preg_replace('/[\s\-()]/', '', $value);

        return \rtrim($value, '/');
    }

    /** Collapse whitespace per line; never more than one blank line. */
    private static function tidy(string $text): string
    {
        $text  = \str_replace("\u{a0}", ' ', $text);
        $lines = \array_map(
            static fn (string $line): string => \trim((string) \preg_replace('/ {2,}/', ' ', $line)),
            \explode("\n", $text),
        );

        return \trim((string) \preg_replace("/\n{3,}/", "\n\n", \implode("\n", $lines)));
    }
}
