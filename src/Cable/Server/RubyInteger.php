<?php

declare(strict_types=1);

namespace App\Cable\Server;

/**
 * ActiveModel::Type::Integer#cast, as `find_by(id: params[:room_id])` casts a JSON value:
 * true/false are 1/0, strings go through String#to_i ("12abc" is 12, "abc" is 0), floats are
 * truncated, anything else (arrays, hashes, nil, blank strings) is nil.
 */
final class RubyInteger
{
    public static function cast(mixed $value): ?int
    {
        return match (true) {
            \is_int($value) => $value,
            true === $value => 1,
            false === $value => 0,
            \is_float($value) => is_finite($value) && abs($value) < 9.2e18 ? (int) $value : null,
            \is_string($value) => '' === trim($value) ? null : self::toI($value),
            default => null,
        };
    }

    /** Ruby String#to_i (base 10): leading whitespace, a sign, digits with single underscores. */
    public static function toI(string $value): ?int
    {
        if (!preg_match('/\A[\s\v]*([+-]?)(\d+(?:_\d+)*)/', $value, $m)) {
            return 0;
        }
        $digits = ltrim(str_replace('_', '', $m[2]), '0');
        if ('' === $digits) {
            return 0;
        }
        if (\strlen($digits) > 19 || (19 === \strlen($digits) && strcmp($digits, '9223372036854775807') > 0)) {
            return null; // out of the 8-byte range Active Record accepts: find_by finds nothing
        }

        return '-' === $m[1] ? -(int) $digits : (int) $digits;
    }
}
