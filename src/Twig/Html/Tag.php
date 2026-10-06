<?php

declare(strict_types=1);

namespace App\Twig\Html;

use App\Rails\RailsJson;
use App\Twig\Escaper\ErbEscaper;
use Twig\Markup;

/**
 * ActionView::Helpers::TagHelper: attribute rendering (tag_options) and the three tag shapes
 * Rails emits — content tags, `tag.<void>` (`<meta …>`) and legacy `tag(:name)` (`<img … />`).
 * Attribute order is the order of the given array, as with a Ruby hash.
 *
 * Values follow Rails: null drops the attribute; Twig\Markup is html_safe (only `"` is escaped);
 * BOOLEAN_ATTRIBUTES render as `name="name"` when truthy; `data`/`aria` hashes expand to prefixed,
 * dasherized attributes (non-string data values are JSON); `class` arrays go through
 * build_tag_values; other true/false values render as "true"/"false" (Ruby's to_s).
 */
final class Tag
{
    public const BOOLEAN_ATTRIBUTES = [
        'allowfullscreen' => true, 'allowpaymentrequest' => true, 'async' => true, 'autofocus' => true,
        'autoplay' => true, 'checked' => true, 'compact' => true, 'controls' => true, 'declare' => true,
        'default' => true, 'defaultchecked' => true, 'defaultmuted' => true, 'defaultselected' => true,
        'defer' => true, 'disabled' => true, 'enabled' => true, 'formnovalidate' => true, 'hidden' => true,
        'indeterminate' => true, 'inert' => true, 'ismap' => true, 'itemscope' => true, 'loop' => true,
        'multiple' => true, 'muted' => true, 'nohref' => true, 'nomodule' => true, 'noresize' => true,
        'noshade' => true, 'novalidate' => true, 'nowrap' => true, 'open' => true, 'pauseonexit' => true,
        'playsinline' => true, 'readonly' => true, 'required' => true, 'reversed' => true, 'scoped' => true,
        'seamless' => true, 'selected' => true, 'sortable' => true, 'truespeed' => true,
        'typemustmatch' => true, 'visible' => true,
    ];

    /**
     * `content_tag(name, content, options)` / `tag.name(content, **options)`.
     *
     * @param array<string, mixed> $options
     */
    public static function content(string $name, mixed $content = null, array $options = [], bool $escape = true): string
    {
        $content = match (true) {
            null === $content, false === $content => '',
            $content instanceof Markup => (string) $content,
            $escape => ErbEscaper::escapeValue($content),
            default => (string) $content,
        };

        return '<'.$name.self::options($options, $escape).'>'.('textarea' === $name ? "\n" : '').$content.'</'.$name.'>';
    }

    /**
     * `tag.meta(**options)`: HTML5 void element, no self-closing slash.
     *
     * @param array<string, mixed> $options
     */
    public static function void(string $name, array $options = []): string
    {
        return '<'.$name.self::options($options).'>';
    }

    /**
     * Legacy `tag(:name, options, open)`: `<name … />`, or `<name …>` when $open.
     *
     * @param array<string, mixed> $options
     */
    public static function legacy(string $name, array $options = [], bool $open = false): string
    {
        return '<'.$name.self::options($options).($open ? '>' : ' />');
    }

    /**
     * `tag_options`: every attribute preceded by a space ("" when there are none).
     *
     * @param array<array-key, mixed> $options
     */
    public static function options(array $options, bool $escape = true): string
    {
        $output = '';
        foreach ($options as $key => $value) {
            $key = (string) $key;
            if (('data' === $key || 'aria' === $key) && \is_array($value) && !array_is_list($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (null === $subValue) {
                        continue;
                    }
                    if ('aria' === $key && \is_array($subValue)) {
                        $tokens = self::buildValues($subValue);
                        if ([] === $tokens) {
                            continue;
                        }
                        $subValue = new Markup(implode(' ', array_map(ErbEscaper::escapeValue(...), $tokens)), 'UTF-8');
                    } elseif ('aria' === $key) {
                        $subValue = self::rubyToS($subValue);
                    } elseif (!\is_string($subValue) && !$subValue instanceof Markup && !$subValue instanceof \BackedEnum) {
                        $subValue = RailsJson::encode($subValue);
                    }
                    $output .= ' '.self::option($key.'-'.Inflector::dasherize((string) $subKey), $subValue, $escape);
                }
            } elseif (isset(self::BOOLEAN_ATTRIBUTES[$key])) {
                if ($value) {
                    $output .= ' '.$key.'="'.$key.'"';
                }
            } elseif (null !== $value) {
                $output .= ' '.self::option($key, $value, $escape);
            }
        }

        return $output;
    }

    /**
     * `build_tag_values`: strings as-is, hashes contribute their keys whose value is truthy,
     * arrays are flattened, blanks dropped.
     *
     * @return list<string>
     */
    public static function buildValues(mixed ...$args): array
    {
        $values = [];
        foreach ($args as $arg) {
            if (\is_array($arg) && !array_is_list($arg)) {
                foreach ($arg as $key => $value) {
                    if ($value && '' !== trim((string) $key)) {
                        $values[] = (string) $key;
                    }
                }
            } elseif (\is_array($arg)) {
                array_push($values, ...self::buildValues(...$arg));
            } elseif (null !== $arg && false !== $arg && '' !== trim(self::rubyToS($arg))) {
                $values[] = self::rubyToS($arg);
            }
        }

        return $values;
    }

    /** `token_list` / `class_names`: unique whitespace-separated tokens. */
    public static function tokenList(mixed ...$args): string
    {
        $tokens = [];
        foreach (self::buildValues(...$args) as $value) {
            foreach (preg_split('/\s+/', html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens[$token] = true;
            }
        }

        return implode(' ', array_map(ErbEscaper::html(...), array_keys($tokens)));
    }

    /** Ruby's to_s for attribute values. */
    public static function rubyToS(mixed $value): string
    {
        return match (true) {
            true === $value => 'true',
            false === $value => 'false',
            null === $value => '',
            \is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e16 ? \sprintf('%.1f', $value) : (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s \U\T\C'),
            default => (string) $value,
        };
    }

    private static function option(string $key, mixed $value, bool $escape): string
    {
        if (\is_array($value)) {
            if ('class' === $key) {
                $value = self::buildValues($value);
            }
            $value = implode(' ', array_map(static fn (mixed $part): string => $escape ? ErbEscaper::escapeValue($part instanceof Markup ? $part : self::rubyToS($part)) : self::rubyToS($part), $value));
        } elseif ($value instanceof Markup) {
            $value = (string) $value;
        } else {
            $value = $escape ? ErbEscaper::html(self::rubyToS($value)) : self::rubyToS($value);
        }

        return $key.'="'.str_replace('"', '&quot;', $value).'"';
    }
}
