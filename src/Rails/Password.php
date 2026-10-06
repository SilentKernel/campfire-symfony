<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * has_secure_password digests: bcrypt-ruby's BCrypt::Password, "$2a$" at BCrypt::Engine.cost
 * (12). bcrypt-ruby and PHP's crypt() share Openwall's crypt_blowfish, so crypt() with a "$2a$"
 * setting yields bcrypt-ruby's digests. Only the first 72 bytes of a password count.
 */
final class Password
{
    public const int COST = 12;
    /** BCrypt::Engine::MIN_COST (ActiveModel::SecurePassword.min_cost in tests). */
    public const int MIN_COST = 4;
    private const int MAX_SECRET_BYTESIZE = 72;

    /** BCrypt::Password.create(password, cost:). */
    public static function hash(#[\SensitiveParameter] string $password, int $cost = self::COST): string
    {
        $cost = max($cost, self::MIN_COST);
        if ($cost > 31) {
            throw new \InvalidArgumentException('bcrypt cost must be at most 31.');
        }
        $salt = strtr(substr(base64_encode(random_bytes(16)), 0, 22), 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
        $digest = crypt(self::secret($password), \sprintf('$2a$%02d$%s', $cost, $salt));
        if (60 !== \strlen($digest)) {
            throw new \RuntimeException('bcrypt failed.');
        }

        return $digest;
    }

    /** BCrypt::Password.new(digest).is_password?(password); false for a missing or malformed digest. */
    public static function verify(#[\SensitiveParameter] string $password, ?string $digest): bool
    {
        // BCrypt::Password#valid_hash?
        if (null === $digest || !preg_match('~\A\$[0-9a-z]{2}\$[0-9]{2}\$[A-Za-z0-9./]{53}\z~', $digest)) {
            return false;
        }

        return hash_equals($digest, crypt(self::secret($password), $digest));
    }

    private static function secret(string $password): string
    {
        // crypt_blowfish reads a C string: it stops at NUL and uses at most 72 bytes.
        $nul = strpos($password, "\0");

        return substr(false === $nul ? $password : substr($password, 0, $nul), 0, self::MAX_SECRET_BYTESIZE);
    }
}
