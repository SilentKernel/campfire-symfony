<?php

declare(strict_types=1);

namespace App\RichText\Sanitizer;

/**
 * `Loofah::HTML5::Scrub.scrub_css` (loofah 2.25.2), which PermitScrubber runs on every `style`
 * attribute it keeps, over a small CSS tokenizer standing in for Crass: each declaration whose
 * property Loofah allows is rebuilt as `name:value;` from the value's allowed parts, and a
 * declaration with a `url(...)` is dropped whole.
 */
final class CssScrubber
{
    private const array ALLOWED_CSS_PROPERTIES = [
        'azimuth', 'align-content', 'align-items', 'align-self', 'aspect-ratio', 'background-color', 'border-bottom-color', 'border-collapse',
        'border-color', 'border-left-color', 'border-right-color', 'border-top-color', 'clear', 'color', 'cursor', 'direction', 'display',
        'elevation', 'flex', 'flex-basis', 'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink', 'flex-wrap', 'float', 'font',
        'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight', 'height', 'justify-content', 'letter-spacing', 'line-height',
        'list-style', 'list-style-type', 'max-height', 'max-width', 'min-height', 'min-width', 'order', 'overflow', 'overflow-x',
        'overflow-y', 'page-break-after', 'page-break-before', 'page-break-inside', 'pause', 'pause-after', 'pause-before', 'pitch',
        'pitch-range', 'richness', 'speak', 'speak-header', 'speak-numeral', 'speak-punctuation', 'speech-rate', 'stress', 'text-align',
        'text-decoration', 'text-indent', 'unicode-bidi', 'vertical-align', 'voice-family', 'volume', 'white-space', 'width',
    ];

    private const array ALLOWED_SVG_PROPERTIES = ['fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity'];

    private const array SHORTHAND_CSS_PROPERTIES = ['background', 'border', 'margin', 'padding'];

    /** ACCEPTABLE_CSS_FUNCTIONS, plus "var" (Lexxy's engine adds it) */
    private const array ALLOWED_CSS_FUNCTIONS = [
        'attr', 'blur', 'brightness', 'calc', 'circle', 'contrast', 'counter', 'counters', 'cubic-bezier', 'drop-shadow', 'ellipse',
        'grayscale', 'hsl', 'hsla', 'hue-rotate', 'hwb', 'inset', 'invert', 'linear-gradient', 'matrix', 'matrix3d', 'opacity',
        'perspective', 'polygon', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'rgb', 'rgba', 'rotate',
        'rotate3d', 'rotateX', 'rotateY', 'rotateZ', 'saturate', 'sepia', 'scale', 'scale3d', 'scaleX', 'scaleY', 'scaleZ', 'skew',
        'skewX', 'skewY', 'symbols', 'translate', 'translate3d', 'translateX', 'translateY', 'translateZ', 'var',
    ];

    /** ACCEPTABLE_CSS_KEYWORDS + ACCEPTABLE_CSS_COLORS + ACCEPTABLE_CSS_EXTENDED_COLORS */
    private const array ALLOWED_CSS_KEYWORDS = [
        '!important', 'auto', 'block', 'bold', 'both', 'bottom', 'center', 'collapse', 'dashed', 'dotted', 'double', 'groove', 'hidden',
        'inherit', 'initial', 'inset', 'italic', 'left', 'medium', 'none', 'normal', 'nowrap', 'outset', 'pointer', 'revert', 'ridge',
        'right', 'separate', 'solid', 'thick', 'thin', 'top', 'transparent', 'underline', 'unset',
        'aqua', 'black', 'blue', 'fuchsia', 'gray', 'green', 'lime', 'maroon', 'navy', 'olive', 'purple', 'red', 'silver', 'teal', 'white',
        'yellow', 'aliceblue', 'antiquewhite', 'aquamarine', 'azure', 'beige', 'bisque', 'blanchedalmond', 'blueviolet', 'brown',
        'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue',
        'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange',
        'darkorchid', 'darkred', 'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise',
        'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen',
        'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred', 'indigo', 'ivory',
        'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan', 'lightgoldenrodyellow',
        'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon', 'lightseagreen', 'lightskyblue', 'lightslategray',
        'lightslategrey', 'lightsteelblue', 'lightyellow', 'limegreen', 'linen', 'magenta', 'mediumaquamarine', 'mediumblue',
        'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise', 'mediumvioletred',
        'midnightblue', 'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'oldlace', 'olivedrab', 'orange', 'orangered', 'orchid',
        'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue',
        'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna', 'skyblue', 'slateblue',
        'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat',
        'whitesmoke', 'yellowgreen',
    ];

    /** `Loofah::HTML5::Scrub::CSS_KEYWORDISH` */
    private const string CSS_KEYWORDISH = '/\A(#[0-9a-fA-F]+|rgb\(\d+%?,\d*%?,?\d*%?\)?|-?\d{0,3}\.?\d{0,10}(ch|cm|r?em|ex|in|lh|mm|pc|pt|px|Q|vmax|vmin|vw|vh|%|,|\))?)\z/';

    public static function scrub(string $style): string
    {
        $out = '';
        foreach (self::declarations(self::tokenize($style)) as [$name, $value, $important]) {
            foreach ($value as $token) {
                if ('url' === $token['type']) {
                    continue 2;
                }
            }
            $name = strtolower($name);
            $shorthand = \in_array(explode('-', $name)[0], self::SHORTHAND_CSS_PROPERTIES, true);
            if (!\in_array($name, self::ALLOWED_CSS_PROPERTIES, true) && !\in_array($name, self::ALLOWED_SVG_PROPERTIES, true) && !$shorthand) {
                continue;
            }
            $parts = '';
            foreach ($value as $token) {
                $parts .= match ($token['type']) {
                    'whitespace' => ' ',
                    'string' => 1 === preg_match('/\A(["\'])?[^"\']+\1\z/', $token['raw']) ? $token['raw'] : '',
                    'function' => \in_array(strtolower($token['value']), self::ALLOWED_CSS_FUNCTIONS, true) ? $token['raw'] : '',
                    'ident' => !$shorthand || \in_array($token['value'], self::ALLOWED_CSS_KEYWORDS, true) || 1 === preg_match(self::CSS_KEYWORDISH, $token['value']) ? $token['value'] : '',
                    'block' => '',
                    default => $token['raw'],
                };
            }
            $parts = trim($parts, " \t\n\r\0\x0B\x0C");
            if ('' === $parts) {
                continue;
            }
            if ($important) {
                $parts .= ' !important';
            }
            $out .= $name.':'.$parts.';';
        }

        return $out;
    }

    /**
     * Crass's `parse_properties`: each declaration's name, value tokens and !important flag.
     *
     * @param list<array{type: string, raw: string, value: string}> $tokens
     *
     * @return list<array{0: string, 1: list<array{type: string, raw: string, value: string}>, 2: bool}>
     */
    private static function declarations(array $tokens): array
    {
        $declarations = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\in_array($token['type'], ['whitespace', 'comment', 'semicolon'], true)) {
                continue;
            }
            $group = [];
            for ($j = $i + 1; $j < $count && 'semicolon' !== $tokens[$j]['type']; ++$j) {
                $group[] = $tokens[$j];
            }
            $i = $j;
            if ('ident' !== $token['type']) {
                continue;
            }
            while ([] !== $group && 'whitespace' === $group[0]['type']) {
                array_shift($group);
            }
            if ([] === $group || 'colon' !== $group[0]['type']) {
                continue;
            }
            $value = \array_slice($group, 1);
            $significant = array_values(array_filter($value, static fn (array $t): bool => !\in_array($t['type'], ['whitespace', 'comment'], true)));
            $last = \array_slice($significant, -2);
            $important = 2 === \count($last) && 'delim' === $last[0]['type'] && '!' === $last[0]['value'] && 'ident' === $last[1]['type'] && 'important' === strtolower($last[1]['value']);
            if ($important) {
                $value = \array_slice($value, 0, (int) array_search($last[0], $value, true));
            }
            $declarations[] = [$token['value'], $value, $important];
        }

        return $declarations;
    }

    /**
     * Component values: functions and (), [] and {} blocks come back whole.
     *
     * @return list<array{type: string, raw: string, value: string}>
     */
    private static function tokenize(string $css, int &$i = 0, ?string $closing = null): array
    {
        $tokens = [];
        $length = \strlen($css);
        while ($i < $length) {
            $c = $css[$i];
            $start = $i;
            if (null !== $closing && $c === $closing) {
                ++$i;

                return $tokens;
            }
            if (1 === preg_match('/\G[ \t\n\r\f]+/', $css, $m, 0, $i)) {
                $i += \strlen($m[0]);
                $tokens[] = ['type' => 'whitespace', 'raw' => $m[0], 'value' => $m[0]];
            } elseif ('/' === $c && '*' === ($css[$i + 1] ?? '')) {
                $end = strpos($css, '*/', $i + 2);
                $i = false === $end ? $length : $end + 2;
                $tokens[] = ['type' => 'comment', 'raw' => substr($css, $start, $i - $start), 'value' => ''];
            } elseif ('"' === $c || "'" === $c) {
                ++$i;
                while ($i < $length && $css[$i] !== $c && "\n" !== $css[$i]) {
                    $i += '\\' === $css[$i] ? 2 : 1;
                }
                $bad = $i >= $length || "\n" === $css[$i];
                if (!$bad) {
                    ++$i;
                }
                $tokens[] = ['type' => $bad ? 'bad_string' : 'string', 'raw' => substr($css, $start, min($i, $length) - $start), 'value' => ''];
            } elseif (1 === preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?(%|-?[a-zA-Z_\x80-\xFF][\w\-\x80-\xFF]*)?/', $css, $m, 0, $i)) {
                $i += \strlen($m[0]);
                $tokens[] = ['type' => 'number', 'raw' => $m[0], 'value' => $m[0]];
            } elseif (1 === preg_match('/\G(?:--|-?[a-zA-Z_\x80-\xFF])[\w\-\x80-\xFF]*/', $css, $m, 0, $i)) {
                $i += \strlen($m[0]);
                if ('(' === ($css[$i] ?? '')) {
                    ++$i;
                    if ('url' === strtolower($m[0]) && 1 !== preg_match('/\G[ \t\n\r\f]*["\']/', $css, $q, 0, $i)) {
                        $end = strpos($css, ')', $i);
                        $i = false === $end ? $length : $end + 1;
                        $tokens[] = ['type' => 'url', 'raw' => substr($css, $start, $i - $start), 'value' => ''];
                    } else {
                        self::tokenize($css, $i, ')');
                        $tokens[] = ['type' => 'function', 'raw' => substr($css, $start, $i - $start), 'value' => $m[0]];
                    }
                } else {
                    $tokens[] = ['type' => 'ident', 'raw' => $m[0], 'value' => $m[0]];
                }
            } elseif ('#' === $c && 1 === preg_match('/\G#[\w\-\x80-\xFF]+/', $css, $m, 0, $i)) {
                $i += \strlen($m[0]);
                $tokens[] = ['type' => 'hash', 'raw' => $m[0], 'value' => $m[0]];
            } elseif ('(' === $c || '[' === $c || '{' === $c) {
                ++$i;
                self::tokenize($css, $i, ['(' => ')', '[' => ']', '{' => '}'][$c]);
                $tokens[] = ['type' => 'block', 'raw' => substr($css, $start, $i - $start), 'value' => ''];
            } else {
                ++$i;
                $type = match ($c) {
                    ';' => 'semicolon',
                    ':' => 'colon',
                    ',' => 'comma',
                    default => 'delim',
                };
                $tokens[] = ['type' => $type, 'raw' => $c, 'value' => $c];
            }
        }

        return $tokens;
    }
}
