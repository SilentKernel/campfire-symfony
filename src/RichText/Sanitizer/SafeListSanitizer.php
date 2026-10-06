<?php

declare(strict_types=1);

namespace App\RichText\Sanitizer;

use App\RichText\Html\Html;
use App\RichText\Html\ParseError;
use Dom\Attr;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * `Rails::HTML5::SafeListSanitizer#sanitize(html, tags:, attributes:)` with its
 * `Rails::HTML::PermitScrubber`, over Loofah's HTML5 scrubbing helpers (rails-html-sanitizer
 * 1.7.1, loofah 2.25.2).
 *
 * Symfony's HtmlSanitizer can't stand in for it: the layers need Loofah's exact rules (unwrapping
 * rather than dropping disallowed HTML elements, dropping foreign elements with their contents,
 * its URI protocol checks and attribute re-escaping) and the markup serialized exactly as
 * Nokogiri does, since auto_link's regular expressions run over the serialized HTML.
 */
final class SafeListSanitizer
{
    /** `Loofah::HTML5::SafeList::ATTR_VAL_IS_URI` */
    private const array ATTR_VAL_IS_URI = ['action' => true, 'cite' => true, 'href' => true, 'longdesc' => true, 'poster' => true, 'preload' => true, 'src' => true, 'xlink:href' => true, 'xml:base' => true];

    /**
     * `sanitize(html, tags:, attributes:)`. With `$escapeAttributeBrackets`, `<` and `>` are also
     * escaped in attribute values, so the result can be scanned with regular expressions
     * (auto_link) without mistaking an attribute for text; Rails leaves them raw.
     *
     * @throws ParseError
     */
    public static function sanitize(string $html, SafeList $list, bool $escapeAttributeBrackets = false): string
    {
        if ('' === $html) {
            return '';
        }

        return Html::toHtml(self::scrubbed($html, $list), $escapeAttributeBrackets);
    }

    /** @throws ParseError */
    public static function scrubbed(string $html, SafeList $list): Element
    {
        $fragment = Html::fragment($html);
        foreach (Html::children($fragment) as $child) {
            self::scrubBottomUp($child, $list);
        }

        return $fragment;
    }

    /**
     * `Loofah::Scrubber#traverse_conditionally_bottom_up`: children (as they were before any of
     * them was scrubbed) first, then the node itself.
     */
    private static function scrubBottomUp(Node $node, SafeList $list): void
    {
        foreach (Html::children($node) as $child) {
            self::scrubBottomUp($child, $list);
        }
        self::scrub($node, $list);
    }

    /** `Rails::HTML::PermitScrubber#scrub` */
    private static function scrub(Node $node, SafeList $list): void
    {
        if ($node instanceof Text) {
            return;
        }
        $parent = $node->parentNode;
        if (!$node instanceof Element || !$list->allowsTag($node->localName)) {
            // Unwrap HTML elements (and comments, which have no children); drop foreign (SVG,
            // MathML) elements together with their contents.
            if (null !== $parent) {
                if (Html::isHtmlElement($node)) {
                    foreach (Html::children($node) as $child) {
                        $parent->insertBefore($child, $node);
                    }
                }
                $parent->removeChild($node);
            }

            return;
        }
        self::scrubAttributes($node, $list);
    }

    /**
     * `PermitScrubber#scrub_attributes` with an attribute allowlist. Attributes are visited in
     * order, and each allowed one re-escapes every URL attribute on the node as it goes
     * (`force_correct_attribute_escaping!`), so a later URL is checked in its re-escaped form.
     */
    private static function scrubAttributes(Element $element, SafeList $list): void
    {
        $isA = 'a' === $element->localName;
        foreach (iterator_to_array($element->attributes) as $attribute) {
            \assert($attribute instanceof Attr);
            $name = $attribute->name;
            $value = $attribute->value;
            if (!$list->allowsAttribute($name) || (isset(self::ATTR_VAL_IS_URI[$name]) && !UriSafety::allowedUri($value))) {
                $element->removeAttributeNode($attribute);
                continue;
            }
            // A blank src goes, but the escaping still runs for the attributes after it
            if ('src' === $name && 1 !== preg_match('/[^\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', $value)) {
                $element->removeAttributeNode($attribute);
            }
            self::forceCorrectAttributeEscaping($element, $isA);
        }
        self::scrubStyle($element);
    }

    /**
     * `Loofah::HTML5::Scrub.force_correct_attribute_escaping!` (libxml2 builds, which CRuby is):
     * spaces and double quotes in `href`, `action`, `src` and an `a`'s `name` become `%20` and
     * `%22`. The value is written back through `Nokogiri::XML::Attr#value=`, where libxml2 drops
     * the C0 controls XML 1.0 doesn't allow.
     */
    private static function forceCorrectAttributeEscaping(Element $element, bool $isA): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            \assert($attribute instanceof Attr);
            $name = $attribute->name;
            if (!\in_array($name, ['href', 'action', 'src'], true) && !('name' === $name && $isA)) {
                continue;
            }
            $value = $attribute->value;
            if (1 === preg_match('/[ "\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                $attribute->value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', strtr($value, [' ' => '%20', '"' => '%22']));
            }
        }
    }

    /** `PermitScrubber#scrub_css_attribute`: Loofah's CSS scrubber over a kept `style`. */
    private static function scrubStyle(Element $element): void
    {
        $style = $element->getAttribute('style');
        if (null !== $style) {
            $element->setAttribute('style', CssScrubber::scrub($style));
        }
    }
}
