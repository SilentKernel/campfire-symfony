<?php

declare(strict_types=1);

namespace App\Opengraph;

/**
 * Opengraph::Document (reference/app/models/opengraph/document.rb): from each `meta` whose
 * `property` or `name` starts with "og:", the key is that attribute (`property` when present)
 * with every "og:" removed and the value its non-blank `content`; later tags win; only
 * Metadata::ATTRIBUTES are kept, in that order. Without a meta charset (Nokogiri's
 * `meta_encoding`), non-ASCII characters are dropped
 * (`content.encode("UTF-8", "binary", invalid: :replace, undef: :replace, replace: "")`).
 */
final readonly class Document
{
    /** @return array<string, string> */
    public static function opengraphAttributes(?string $html): array
    {
        if (null === $html || '' === $html) {
            return [];
        }

        $encoding = self::metaEncoding($html);
        $utf8 = self::toUtf8($html, $encoding);
        $document = \Dom\HTMLDocument::createFromString($utf8, \LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');

        $found = [];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            $property = $meta->getAttribute('property');
            $name = $meta->getAttribute('name');
            if (!(null !== $property && str_starts_with($property, 'og:')) && !(null !== $name && str_starts_with($name, 'og:'))) {
                continue;
            }
            $key = str_replace('og:', '', (string) ($meta->hasAttribute('property') ? $property : $name));
            $content = $meta->getAttribute('content');
            if (null === $content || self::isBlank($content)) {
                continue;
            }
            $found[$key] = null !== $encoding ? $content : (string) preg_replace('/[^\x00-\x7F]/u', '', $content);
        }

        $attributes = [];
        foreach (Metadata::ATTRIBUTES as $attribute) {
            if (isset($found[$attribute])) {
                $attributes[$attribute] = $found[$attribute];
            }
        }

        return $attributes;
    }

    /** `String#blank?` */
    public static function isBlank(?string $value): bool
    {
        return null === $value || 1 === preg_match('/\A[[:space:]\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]*\z/u', $value);
    }

    /** Nokogiri's HTML::Document#meta_encoding: `<meta charset>` or an http-equiv Content-Type's charset. */
    private static function metaEncoding(string $html): ?string
    {
        if (1 === preg_match('/<meta\b[^>]*\bcharset\s*=\s*["\']?([A-Za-z0-9_.:\-]+)/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function toUtf8(string $html, ?string $encoding): string
    {
        if (null !== $encoding && !\in_array(strtolower($encoding), ['utf-8', 'utf8'], true)) {
            try {
                $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
                if (\is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
            }
        }

        // libxml2 reads a document that isn't valid UTF-8 as ISO-8859-1.
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }
}
