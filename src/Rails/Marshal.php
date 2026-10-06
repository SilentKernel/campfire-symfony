<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * The sliver of Ruby's Marshal format needed to read Rails 7.0-era signed messages, whose payload
 * is a marshaled String (Marshal.dump("gid://campfire/User/1")). Anything else is rejected.
 */
final class Marshal
{
    public const string SIGNATURE = "\x04\x08";

    /** Loads "\x04\x08" ["I"] '"' <length> <bytes> [ivars]; null for anything else. */
    public static function loadString(string $dumped): ?string
    {
        if (!str_starts_with($dumped, self::SIGNATURE)) {
            return null;
        }
        $offset = 2;
        if ('I' === ($dumped[$offset] ?? '')) {
            ++$offset;
        }
        if ('"' !== ($dumped[$offset] ?? '')) {
            return null;
        }
        ++$offset;
        $length = self::readFixnum($dumped, $offset);
        if (null === $length || $length < 0 || \strlen($dumped) < $offset + $length) {
            return null;
        }

        return substr($dumped, $offset, $length);
    }

    /** marshal.c r_long. */
    private static function readFixnum(string $bytes, int &$offset): ?int
    {
        if (!isset($bytes[$offset])) {
            return null;
        }
        $first = \ord($bytes[$offset++]);
        $first = $first > 127 ? $first - 256 : $first;

        if (0 === $first) {
            return 0;
        }
        if ($first >= 5) {
            return $first - 5;
        }
        if ($first <= -5) {
            return $first + 5;
        }

        $count = abs($first);
        if (\strlen($bytes) < $offset + $count) {
            return null;
        }
        $value = 0;
        for ($i = 0; $i < $count; ++$i) {
            $value |= \ord($bytes[$offset + $i]) << (8 * $i);
        }
        $offset += $count;
        if ($first < 0) {
            $value -= 1 << (8 * $count);
        }

        return $value;
    }
}
