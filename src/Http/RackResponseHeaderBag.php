<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * A response header bag that sends Cache-Control exactly as set ("max-age=0, private,
 * must-revalidate", "no-cache"), the way Rack does, instead of Symfony's normalized and completed
 * value ("no-cache, private"). The pipeline swaps it in as the last step of kernel.response.
 */
final class RackResponseHeaderBag extends ResponseHeaderBag
{
    private const array CACHE_HEADERS = ['cache-control', 'etag', 'last-modified', 'expires'];

    private ?string $rawCacheControl = null;

    /** Whether a Cache-Control directive was set explicitly (Symfony always computes a default). */
    public static function hasExplicitCacheControl(ResponseHeaderBag $headers): bool
    {
        return $headers instanceof self ? null !== $headers->rawCacheControl : [] !== $headers->cacheControl;
    }

    public static function from(ResponseHeaderBag $headers, ?string $cacheControl): self
    {
        $bag = new self($headers->allPreserveCaseWithoutCookies());
        foreach ($headers->getCookies() as $cookie) {
            $bag->setCookie($cookie);
        }
        if (null === $cacheControl || '' === $cacheControl) {
            $bag->remove('Cache-Control');
        } else {
            $bag->set('Cache-Control', $cacheControl);
        }

        return $bag;
    }

    public function set(string $key, string|array|null $values, bool $replace = true): void
    {
        $uniqueKey = strtolower($key);
        if ('cache-control' === $uniqueKey) {
            $value = implode(', ', array_filter((array) $values, static fn (?string $v): bool => null !== $v && '' !== $v));
            $this->rawCacheControl = '' === $value ? null : $value;
        }

        parent::set($key, $values, $replace);

        if (\in_array($uniqueKey, self::CACHE_HEADERS, true)) {
            $this->restoreCacheControl();
        }
    }

    public function remove(string $key): void
    {
        if ('cache-control' === strtolower($key)) {
            $this->rawCacheControl = null;
        }

        parent::remove($key);
    }

    private function restoreCacheControl(): void
    {
        if (null === $this->rawCacheControl) {
            unset($this->headers['cache-control'], $this->headerNames['cache-control']);
            $this->cacheControl = [];

            return;
        }

        $this->headers['cache-control'] = [$this->rawCacheControl];
        $this->headerNames['cache-control'] = 'Cache-Control';
    }
}
