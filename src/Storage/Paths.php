<?php

declare(strict_types=1);

namespace App\Storage;

/** Journey's path escaping for route segments and globs (`ActionDispatch::Journey::Router::Utils`). */
final class Paths
{
    /** `escape_path`: keeps unreserved, sub-delims, ":", "@" and "/". */
    public static function escapePath(string $value): string
    {
        return self::escape($value, "-._~!$&'()*+,;=:@/");
    }

    /** `escape_segment`: like escapePath, but "/" is escaped too. */
    public static function escapeSegment(string $value): string
    {
        return self::escape($value, "-._~!$&'()*+,;=:@");
    }

    /** `CGI.escape`, as `Hash#to_query` escapes query values. */
    public static function cgiEscape(string $value): string
    {
        return str_replace('%7E', '~', urlencode($value));
    }

    private static function escape(string $value, string $keep): string
    {
        $out = '';
        $length = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $byte = $value[$i];
            $out .= 1 === preg_match('/[A-Za-z0-9]/', $byte) || str_contains($keep, $byte) ? $byte : '%'.strtoupper(bin2hex($byte));
        }

        return $out;
    }
}
