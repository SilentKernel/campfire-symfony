<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

/**
 * A normalized, line-per-node rendering of an HTML page for comparing ours with the Rails
 * reference (tests/Functional/Rooms/fixtures, captured from campfire-reference:app as David with
 * `curl/8.7.1`): whitespace collapsed, asset digests dropped (Propshaft's and AssetMapper's
 * differ), CSRF tokens blanked, the reference host replaced by ours. Attribute order is kept.
 */
final class RailsDom
{
    public const string REFERENCE_HOST = 'http://127.0.0.1:3393';
    public const string HOST = 'http://localhost';

    /**
     * @param list<string> $removeSelectors elements left out on both sides (pending other workstreams)
     *
     * @return list<string>
     */
    public static function lines(string $html, array $removeSelectors = [], bool $bodyOnly = true): array
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        foreach ($removeSelectors as $selector) {
            foreach (iterator_to_array($document->querySelectorAll($selector)) as $node) {
                $node->remove();
            }
        }
        $root = $bodyOnly ? ($document->body ?? $document->documentElement) : $document->documentElement;
        \assert(null !== $root);
        // The lightbox and app logo come from the shared layout.
        foreach (iterator_to_array($root->querySelectorAll('dialog.lightbox, #app-logo')) as $node) {
            $node->remove();
        }

        $lines = [];
        self::walk($root, 0, $lines);

        return $lines;
    }

    /** @param list<string> $lines */
    private static function walk(\Dom\Node $node, int $depth, array &$lines): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Element) {
                $attributes = '';
                foreach ($child->attributes as $attribute) {
                    $value = self::value($attribute->value);
                    if (\in_array($attribute->name, ['value', 'content'], true) && \in_array($child->getAttribute('name'), ['authenticity_token', 'csrf-token'], true)) {
                        $value = 'TOKEN';
                    }
                    $attributes .= \sprintf(' %s="%s"', $attribute->name, $value);
                }
                $lines[] = str_repeat('  ', $depth).'<'.strtolower($child->tagName).$attributes.'>';
                if ('template' === strtolower($child->tagName)) {
                    // A template's markup lives in its content fragment: walk a parse of it.
                    $fragment = \Dom\HTMLDocument::createFromString('<body>'.$child->innerHTML, \LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
                    self::walk($fragment->body ?? $fragment, $depth + 1, $lines);
                } else {
                    self::walk($child, $depth + 1, $lines);
                }
            } elseif ($child instanceof \Dom\Text) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $child->data));
                if ('' !== $text) {
                    $lines[] = str_repeat('  ', $depth).self::value($text);
                }
            }
        }
    }

    private static function value(string $value): string
    {
        $value = str_replace(self::REFERENCE_HOST, self::HOST, $value);
        // QR code links carry the join URL, host included, Base64-encoded.
        $value = (string) preg_replace_callback('#/qr_code/([A-Za-z0-9_=-]+)#', static fn (array $match): string => '/qr_code/'.base64_encode(str_replace(self::REFERENCE_HOST, self::HOST, (string) base64_decode(strtr($match[1], '-_', '+/'), true))), $value);

        return (string) preg_replace('#/assets/(.+?)-[A-Za-z0-9_-]{7,8}\.(\w+)#', '/assets/$1.$2', $value);
    }
}
