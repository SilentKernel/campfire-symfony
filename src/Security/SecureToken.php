<?php

declare(strict_types=1);

namespace App\Security;

/** `has_secure_token`: `SecureRandom.base58(24)`. */
final class SecureToken
{
    private const string BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function generate(int $length = 24): string
    {
        $token = '';
        foreach (str_split(random_bytes($length)) as $byte) {
            // SecureRandom.base58: byte % 64, re-drawn uniformly when it falls past the alphabet.
            $index = \ord($byte) % 64;
            $token .= self::BASE58[$index < 58 ? $index : random_int(0, 57)];
        }

        return $token;
    }
}
