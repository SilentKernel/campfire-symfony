<?php

declare(strict_types=1);

namespace App\RichText;

/**
 * The Ruby and Active Support string behaviours the rich text pipeline depends on.
 */
final class Ruby
{
    /** `String#strip`: ASCII whitespace and NUL, at both ends. */
    public static function strip(string $value): string
    {
        return trim($value, " \t\n\r\0\x0B\x0C");
    }

    /** Active Support's `String#blank?`: empty or only Unicode whitespace (`/\A[[:space:]]*\z/`). */
    public static function isBlank(?string $value): bool
    {
        return null === $value || '' === $value || 1 === preg_match('/(*UCP)\A[\s\x{85}\x{180E}]*\z/u', $value);
    }

    /** `Object#presence` for an optional string. */
    public static function presence(?string $value): ?string
    {
        return self::isBlank($value) ? null : $value;
    }

    /** `String#chomp("")`: removes every trailing `\n` or `\r\n`, but not a lone `\r`. */
    public static function chompNewlines(string $value): string
    {
        while (true) {
            if (str_ends_with($value, "\r\n")) {
                $value = substr($value, 0, -2);
            } elseif (str_ends_with($value, "\n")) {
                $value = substr($value, 0, -1);
            } else {
                return $value;
            }
        }
    }

    /** `String#chomp`: removes one trailing `\r\n`, `\n` or `\r`. */
    public static function chomp(string $value): string
    {
        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }
        if (str_ends_with($value, "\n") || str_ends_with($value, "\r")) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    /** Action View's `truncate(text, length:, omission:)` with the default separator, before escaping. */
    public static function truncate(string $text, int $length, string $omission = '...'): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        $keep = max(0, $length - mb_strlen($omission, 'UTF-8'));

        return mb_substr($text, 0, $keep, 'UTF-8').$omission;
    }

    /** `ERB::Util.html_escape` */
    public static function h(string $value): string
    {
        $escaped = htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return str_replace('&#039;', '&#39;', $escaped);
    }

    /**
     * Ruby's `JSON.parse` (json 2.x), which unlike PHP's parser also skips `/* *\/` and `//`
     * comments. Objects become \stdClass, arrays lists.
     *
     * @throws \JsonException
     */
    public static function jsonParse(string $json): mixed
    {
        $stripped = self::stripJsonComments($json);
        if (null === $stripped) {
            throw new \JsonException('unexpected end of input');
        }

        return json_decode($stripped, false, 10000, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
    }

    /** `Object#to_s` of a parsed JSON value, as Nokogiri applies to attribute values. */
    public static function jsonValueToS(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            null === $value => '',
            default => self::inspect($value),
        };
    }

    /** `Object#inspect` for parsed JSON values, in Ruby 3.4's format (`{"a" => 1}`). */
    public static function inspect(mixed $value): string
    {
        if (null === $value) {
            return 'nil';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return \App\Rails\RubyFloat::toS($value);
        }
        if (\is_string($value)) {
            return self::stringInspect($value);
        }
        if (\is_array($value)) {
            return '['.implode(', ', array_map(static fn ($v) => self::inspect($v), $value)).']';
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ([] === $properties) {
                return '{}';
            }
            $pairs = [];
            foreach ($properties as $key => $item) {
                $pairs[] = self::stringInspect((string) $key).' => '.self::inspect($item);
            }

            return '{'.implode(', ', $pairs).'}';
        }

        return '';
    }

    private static function stringInspect(string $value): string
    {
        $out = '"';
        $length = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $c = $value[$i];
            $next = $value[$i + 1] ?? '';
            $out .= match (true) {
                '"' === $c => '\\"',
                '\\' === $c => '\\\\',
                "\n" === $c => '\\n',
                "\t" === $c => '\\t',
                "\r" === $c => '\\r',
                "\x0C" === $c => '\\f',
                "\x0B" === $c => '\\v',
                "\x08" === $c => '\\b',
                "\x07" === $c => '\\a',
                "\x1B" === $c => '\\e',
                '#' === $c && \in_array($next, ['{', '$', '@'], true) => '\\#',
                \ord($c) < 0x20 || "\x7F" === $c => \sprintf('\\x%02X', \ord($c)),
                default => $c,
            };
        }

        return $out.'"';
    }

    private static function stripJsonComments(string $json): ?string
    {
        if (!str_contains($json, '/')) {
            return $json;
        }
        $out = '';
        $length = \strlen($json);
        $inString = false;
        for ($i = 0; $i < $length; ++$i) {
            $c = $json[$i];
            if ($inString) {
                $out .= $c;
                if ('\\' === $c) {
                    if ($i + 1 >= $length) {
                        return null;
                    }
                    $out .= $json[++$i];
                } elseif ('"' === $c) {
                    $inString = false;
                }
                continue;
            }
            $next = $json[$i + 1] ?? '';
            if ('"' === $c) {
                $inString = true;
                $out .= $c;
            } elseif ('/' === $c && '*' === $next) {
                $end = strpos($json, '*/', $i + 2);
                if (false === $end) {
                    return null;
                }
                $i = $end + 1;
                $out .= ' ';
            } elseif ('/' === $c && '/' === $next) {
                $end = strpos($json, "\n", $i);
                $i = false === $end ? $length : $end;
                $out .= ' ';
            } else {
                $out .= $c;
            }
        }

        return $out;
    }
}
