<?php

declare(strict_types=1);

namespace App\Domain\Users;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * SessionsController's `rate_limit to: 10, within: 3.minutes, only: :create`: a fixed window per
 * remote IP (Rails' cache key "rate-limit:sessions:<ip>") counted in the app cache pool, which
 * the FrankenPHP worker threads share (APCu), as Rails shares its cache store.
 */
final readonly class SignInRateLimiter
{
    public const int LIMIT = 10;
    public const string WITHIN = '3 minutes';

    private RateLimiterFactory $factory;

    public function __construct(#[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache)
    {
        $this->factory = new RateLimiterFactory(
            ['id' => 'rate-limit', 'policy' => 'fixed_window', 'limit' => self::LIMIT, 'interval' => self::WITHIN],
            new CacheStorage($cache),
        );
    }

    /** Counts one attempt from $ip; false once the limit is exceeded within the window. */
    public function attempt(string $ip): bool
    {
        return $this->factory->create('sessions:'.$ip)->consume()->isAccepted();
    }
}
