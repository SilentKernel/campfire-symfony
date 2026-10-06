<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * The JSON Rails writes and reads.
 *
 * generate() is the json gem's JSON.generate (JSON.dump): compact, keys in insertion order, "/" and
 * non-ASCII left alone, control characters escaped, floats in the gem's own layout (RubyFloat).
 * encode() is ActiveSupport::JSON.encode on top of it, with escape_html_entities_in_json: "<", ">"
 * and "&" become \u003c, \u003e and \u0026. U+2028/U+2029 stay raw because load_defaults 8.1+
 * sets escape_js_separators_in_json = false (reference app: load_defaults 8.2); pass
 * $escapeJsSeparators to get the older behaviour.
 *
 * PHP values map as: list arrays → JSON arrays, other arrays and \stdClass → objects (an empty
 * PHP array is "[]"; use new \stdClass() for "{}"), \JsonSerializable → its jsonSerialize(),
 * \BackedEnum → its value, \DateTimeInterface → Time#as_json ("2026-01-01T12:00:00.000Z"),
 * non-finite floats → null (Float#as_json).
 */
final class RailsJson
{
    private const int STRING_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_THROW_ON_ERROR;

    /** ActiveSupport::JSON.encode. */
    public static function encode(mixed $value, bool $escapeJsSeparators = false): string
    {
        $json = self::generate($value);
        $escapes = ['<' => self::unicodeEscape('003c'), '>' => self::unicodeEscape('003e'), '&' => self::unicodeEscape('0026')];
        if ($escapeJsSeparators) {
            $escapes += [mb_chr(0x2028) => self::unicodeEscape('2028'), mb_chr(0x2029) => self::unicodeEscape('2029')];
        }

        return strtr($json, $escapes);
    }

    /** ::JSON.generate / JSON.dump. */
    public static function generate(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            true === $value => 'true',
            false === $value => 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => RubyFloat::toJson($value),
            \is_string($value) => json_encode($value, self::STRING_FLAGS),
            \is_array($value) => array_is_list($value) ? self::generateList($value) : self::generateObject($value),
            $value instanceof \JsonSerializable => self::generate($value->jsonSerialize()),
            $value instanceof \stdClass => self::generateObject(get_object_vars($value)),
            $value instanceof \BackedEnum => self::generate($value->value),
            $value instanceof \DateTimeInterface => self::generate(self::time($value)),
            default => throw new \InvalidArgumentException(\sprintf('Cannot encode %s as JSON.', get_debug_type($value))),
        };
    }

    /**
     * ActiveSupport::JSON.decode (::JSON.parse): objects become associative arrays. Integers
     * beyond PHP_INT_MAX come back as numeric strings.
     *
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
    }

    /** Time#as_json / TimeWithZone#as_json: xmlschema with millisecond precision. */
    public static function time(\DateTimeInterface $time): string
    {
        $offset = $time->getOffset();

        return $time->format('Y-m-d\TH:i:s.v').(0 === $offset ? 'Z' : $time->format('P'));
    }

    /** A JSON "\uXXXX" escape. */
    private static function unicodeEscape(string $hex): string
    {
        return '\\u'.$hex;
    }

    /** @param list<mixed> $list */
    private static function generateList(array $list): string
    {
        return '['.implode(',', array_map(self::generate(...), $list)).']';
    }

    /** @param array<array-key, mixed> $object */
    private static function generateObject(array $object): string
    {
        $members = [];
        foreach ($object as $key => $member) {
            $members[] = json_encode((string) $key, self::STRING_FLAGS).':'.self::generate($member);
        }

        return '{'.implode(',', $members).'}';
    }
}
