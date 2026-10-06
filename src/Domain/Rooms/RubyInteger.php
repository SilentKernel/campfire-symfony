<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * Ruby's String#to_i and Active Model's integer cast, for ids taken from params and cookies
 * (`find_by(id: params[:id])`, `cookies[:last_room]`). Checked against
 * tests/vectors/ruby_core.json (strings[].to_i / integer_cast).
 */
final class RubyInteger
{
    /**
     * `String#to_i` (base 10): leading whitespace, a sign, an optional "0d" prefix, then digits
     * with single underscores between them. Returned as a decimal string (it may not fit an int).
     */
    public static function toI(string $value): string
    {
        if (1 !== preg_match('/\A[ \t\n\v\f\r]*([+-]?)(?:0[dD](?=\d))?(\d+(?:_\d+)*)/', $value, $match)) {
            return '0';
        }
        $digits = ltrim(str_replace('_', '', $match[2]), '0');
        if ('' === $digits) {
            return '0';
        }

        return ('-' === $match[1] ? '-' : '').$digits;
    }

    /**
     * ActiveModel::Type::Integer#cast for a param: null for non-numeric strings (and anything that
     * isn't a string or an int); null too when the value is out of the 8-byte range, where Active
     * Model raises ActiveModel::RangeError and a `find_by` finds nothing.
     */
    public static function cast(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (!\is_string($value) || 1 !== preg_match('/\A[ \t\n\v\f\r]*[+-]?\d/', $value)) {
            return null;
        }

        return self::toInt(self::toI($value));
    }

    /** The decimal string as an int, or null outside PHP_INT_MIN..PHP_INT_MAX. */
    private static function toInt(string $decimal): ?int
    {
        $negative = str_starts_with($decimal, '-');
        $digits = $negative ? substr($decimal, 1) : $decimal;
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        if (\strlen($digits) > \strlen($limit) || (\strlen($digits) === \strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }
        if ($negative && $digits === $limit) {
            return \PHP_INT_MIN;
        }

        return (int) $decimal;
    }
}
