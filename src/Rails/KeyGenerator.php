<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Rails.application.key_generator: an ActiveSupport::CachingKeyGenerator over PBKDF2-HMAC with
 * 1000 iterations (railties Rails::Application#key_generator) and SHA256
 * (load_defaults 7.0 key_generator_hash_digest_class). Keys are derived once per process: the
 * cache depends only on the secret, so it is safe to keep across requests in worker mode.
 */
final class KeyGenerator
{
    public const int ITERATIONS = 1000;

    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(#[\SensitiveParameter] private readonly string $secretKeyBase)
    {
    }

    /** Raw key bytes, like generate_key(salt, length). */
    public function generateKey(string $salt, int $length = 64): string
    {
        return $this->cache[$salt.'|'.$length] ??= hash_pbkdf2('sha256', $this->secretKeyBase, $salt, self::ITERATIONS, $length, true);
    }
}
