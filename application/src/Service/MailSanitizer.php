<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Defuses HTML before it is shown inside the app – the preview of an HTML
 * signature, for instance.
 *
 * Only a fixed list of harmless elements and attributes survives; scripts,
 * embedded frames, forms, event attributes and external images are dropped.
 * External images are blocked by default so that tracking pixels cannot tell
 * that and when something was opened – for one's own content, such as a
 * signature, they may be allowed.
 */
final class MailSanitizer
{
    private const array ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'caption', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'small', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const array ALLOWED_ATTRS = [
        'href', 'title', 'alt', 'colspan', 'rowspan', 'style', 'src', 'width', 'height',
        // The old-school table attributes HTML mails and signatures rely on,
        // because some mail clients cannot do CSS for them.
        'align', 'valign', 'bgcolor', 'cellpadding', 'cellspacing', 'border',
    ];

    /** Presentational CSS properties allowed to stay in a style attribute. */
    private const array ALLOWED_STYLES = [
        'color', 'background', 'background-color', 'opacity',
        'font', 'font-weight', 'font-style', 'font-size', 'font-family',
        'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'text-transform',
        'vertical-align', 'white-space', 'display',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-radius', 'border-collapse', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
    ];

    /**
     * @param bool $allowRemoteImages keep images with an http(s) address –
     *                                for one's own content such as the
     *                                signature, where no tracking pixel is
     *                                to be feared
     *
     * @return array{html: string, blockedImages: int}
     */
    public function cleanWithReport(string $html, bool $allowRemoteImages = false): array
    {
        if (\trim($html) === '') {
            return ['html' => '', 'blockedImages' => 0];
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

        if ($loaded === false) {
            return ['html' => \nl2br(\htmlspecialchars(\strip_tags($html), \ENT_QUOTES, 'UTF-8')), 'blockedImages' => 0];
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return ['html' => \nl2br(\htmlspecialchars(\strip_tags($html), \ENT_QUOTES, 'UTF-8')), 'blockedImages' => 0];
        }

        // Clean from the body down, not from <html>: html/head/body are not on
        // the allow list themselves and would otherwise be unwrapped as well.
        $blocked = 0;
        $this->scrub($body, $blocked, $allowRemoteImages);

        $out = '';

        foreach ($body->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return ['html' => $out, 'blockedImages' => $blocked];
    }

    public function clean(string $html, bool $allowRemoteImages = false): string
    {
        return $this->cleanWithReport($html, $allowRemoteImages)['html'];
    }

    private function scrub(\DOMNode $node, int &$blocked, bool $allowRemoteImages): void
    {
        // Walk backwards so that removing nodes does not disturb the list.
        for ($i = $node->childNodes->length - 1; $i >= 0; --$i) {
            $child = $node->childNodes->item($i);

            if ($child === null) {
                continue;
            }

            if ($child instanceof \DOMComment) {
                $node->removeChild($child);

                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = \strtolower($child->nodeName);

            if (\in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base'], true)) {
                $node->removeChild($child);

                continue;
            }

            if (!\in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap unknown elements but keep their content.
                $this->scrub($child, $blocked, $allowRemoteImages);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $this->scrubAttributes($child, $tag, $blocked, $allowRemoteImages);
            $this->scrub($child, $blocked, $allowRemoteImages);
        }
    }

    private function scrubAttributes(\DOMElement $element, string $tag, int &$blocked, bool $allowRemoteImages): void
    {
        for ($i = $element->attributes->length - 1; $i >= 0; --$i) {
            $attribute = $element->attributes->item($i);

            if ($attribute === null) {
                continue;
            }

            $name  = \strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (!\in_array($name, self::ALLOWED_ATTRS, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'style') {
                $clean = $this->cleanStyle($value);
                $clean === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $clean);

                continue;
            }

            if ($name === 'href' && !$this->isSafeUrl($value)) {
                $element->removeAttribute('href');

                continue;
            }

            if ($name === 'src') {
                // Embedded images (data:) are harmless, external ones are not –
                // unless the content is one's own.
                $lower = \strtolower(\trim($value));
                $safe  = $tag === 'img' && (
                    \str_starts_with($lower, 'data:image/')
                    || ($allowRemoteImages && \preg_match('#^https?://#', $lower) === 1)
                );

                if (!$safe) {
                    $element->removeAttribute('src');
                    $element->setAttribute('data-blocked-src', $value);
                    ++$blocked;
                }
            }
        }

        if ($tag === 'a') {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (\explode(';', $style) as $declaration) {
            $parts = \explode(':', $declaration, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            $property = \strtolower(\trim($parts[0]));
            $value    = \trim($parts[1]);

            if (!\in_array($property, self::ALLOWED_STYLES, true)) {
                continue;
            }

            // url(...) and expression(...) can load data or run code.
            if (\preg_match('/(url\s*\(|expression\s*\(|javascript:)/i', $value) === 1) {
                continue;
            }

            $kept[] = $property.':'.$value;
        }

        return \implode(';', $kept);
    }

    private function isSafeUrl(string $url): bool
    {
        $url = \trim($url);

        return \preg_match('#^(https?:|mailto:|tel:|\#)#i', $url) === 1;
    }
}
