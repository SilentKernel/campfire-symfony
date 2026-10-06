<?php

declare(strict_types=1);

namespace App\RichText;

use App\RichText\Html\Html;
use Dom\Element;
use Dom\Node;

/**
 * `ActionText::PlainTextConversion`: a bottom-up reduction keyed on each node's name.
 */
final class PlainTextConversion
{
    public static function nodeToPlainText(Node $node): string
    {
        return Ruby::chompNewlines(self::plainTextFor($node));
    }

    private static function plainTextFor(Node $node): string
    {
        $childValues = static function () use ($node): string {
            $out = '';
            for ($child = $node->firstChild; null !== $child; $child = $child->nextSibling) {
                $out .= self::plainTextFor($child);
            }

            return $out;
        };

        switch (Html::name($node)) {
            case 'script':
            case 'style':
            case 'unsupported':
                return '';
            case 'h1':
            case 'p':
                return Ruby::chompNewlines($childValues())."\n\n";
            case 'ul':
            case 'ol':
                $text = Ruby::chompNewlines($childValues())."\n\n";

                return self::listDepth($node) > 0 ? "\n".$text : $text;
            case 'br':
                return "\n";
            case 'text':
                // Text nodes, and elements that happen to be named "text" (SVG's), use `node.text`
                return Ruby::chompNewlines(Html::textContent($node));
            case 'div':
                return Ruby::chompNewlines($childValues())."\n";
            case 'figcaption':
                return '['.Ruby::chompNewlines($childValues()).']';
            case 'blockquote':
                $text = Ruby::chompNewlines($childValues())."\n\n";
                if (Ruby::isBlank($text)) {
                    return '“”';
                }
                // `text.insert(text.rindex(/\S/) + 1, "”")`, then `text.index(/\S/)` for "“"
                if (1 === preg_match('/[^ \t\n\x0B\x0C\r](?=[ \t\n\x0B\x0C\r]*\z)/u', $text, $m, \PREG_OFFSET_CAPTURE)) {
                    $at = $m[0][1] + \strlen($m[0][0]);
                    $text = substr($text, 0, $at).'”'.substr($text, $at);
                }
                if (1 === preg_match('/[^ \t\n\x0B\x0C\r]/u', $text, $m, \PREG_OFFSET_CAPTURE)) {
                    $text = substr($text, 0, $m[0][1]).'“'.substr($text, $m[0][1]);
                }

                return $text;
            case 'li':
                $bullet = self::bulletForLi($node);
                $text = Ruby::chompNewlines($childValues());
                $depth = self::listDepth($node);

                return ($depth > 1 ? str_repeat('  ', $depth - 1) : '').$bullet.' '.$text."\n";
            default:
                return $childValues();
        }
    }

    private static function listDepth(Node $node): int
    {
        $depth = 0;
        for ($ancestor = $node->parentNode; null !== $ancestor; $ancestor = $ancestor->parentNode) {
            $name = Html::name($ancestor);
            if ('ul' === $name || 'ol' === $name) {
                ++$depth;
            }
        }

        return $depth;
    }

    private static function bulletForLi(Node $node): string
    {
        for ($ancestor = $node->parentNode; null !== $ancestor; $ancestor = $ancestor->parentNode) {
            $name = Html::name($ancestor);
            if ('ol' === $name) {
                $parent = $node->parentNode;
                $index = null !== $parent && $node instanceof Element ? array_search($node, Html::elementChildren($parent), true) : 0;

                return ((false === $index ? 0 : $index) + 1).'.';
            }
            if ('ul' === $name) {
                break;
            }
        }

        return '•';
    }
}
