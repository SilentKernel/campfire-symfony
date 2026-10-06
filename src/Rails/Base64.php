<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Ruby's Base64 flavours, with Ruby's strictness: PHP's base64_decode($s, true) accepts missing
 * padding and non-zero trailing bits, which Base64.strict_decode64 rejects.
 */
final class Base64
{
    private const string STRICT = '~\A(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?\z~';

    /** Base64.strict_encode64. */
    public static function strictEncode(string $data): string
    {
        return base64_encode($data);
    }

    /** Base64.urlsafe_encode64(data, padding:). */
    public static function urlsafeEncode(string $data, bool $padding = true): string
    {
        $encoded = strtr(base64_encode($data), '+/', '-_');

        return $padding ? $encoded : rtrim($encoded, '=');
    }

    /** Base64.strict_decode64; null where Ruby raises ArgumentError. */
    public static function strictDecode(string $encoded): ?string
    {
        if (!preg_match(self::STRICT, $encoded)) {
            return null;
        }
        // Ruby (pack.c, "m0") rejects encodings whose unused trailing bits are not zero.
        if (str_ends_with($encoded, '==')) {
            if (self::sextet($encoded[\strlen($encoded) - 3]) & 0x0F) {
                return null;
            }
        } elseif (str_ends_with($encoded, '=')) {
            if (self::sextet($encoded[\strlen($encoded) - 2]) & 0x03) {
                return null;
            }
        }

        $decoded = base64_decode($encoded, true);

        return false === $decoded ? null : $decoded;
    }

    /**
     * Base64.urlsafe_decode64: pads an unpadded string, maps "-_" to "+/" and strict-decodes, so
     * either alphabet (even mixed) and optional padding are accepted.
     */
    public static function urlsafeDecode(string $encoded): ?string
    {
        if (!str_ends_with($encoded, '=') && 0 !== \strlen($encoded) % 4) {
            $encoded = str_pad($encoded, (\strlen($encoded) + 3) & ~3, '=');
        }

        return self::strictDecode(strtr($encoded, '-_', '+/'));
    }

    private static function sextet(string $char): int
    {
        return (int) strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', $char);
    }
}
