<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Thruster (`thrust bin/start-app`, reference/Procfile) caches publicly cacheable responses, and
 * drops their Set-Cookie before passing them on, so the Rails image never sends a cookie (the
 * session or session_token refresh) on an avatar, the account logo or an Active Storage file.
 * Caddy has no such cache, so the app applies the same rule, after the cookie session commit.
 *
 * Thruster v0.1.23 internal/cache_handler.go (shouldCacheRequest) and
 * internal/cacheable_response.go (CacheStatus, scrubHeaders).
 */
final class ThrusterCacheListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -2056)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (self::shouldCacheRequest($event->getRequest()) && self::isCacheable($response)) {
            $response->headers->remove('Set-Cookie');
        }
    }

    /** CacheHandler#shouldCacheRequest: GET or HEAD, not an upgrade, not a range. */
    public static function shouldCacheRequest(Request $request): bool
    {
        $headers = $request->headers;

        return \in_array($request->getRealMethod(), ['GET', 'HEAD'], true)
            && 'Upgrade' !== $headers->get('Connection')
            && 'websocket' !== $headers->get('Upgrade')
            && '' === (string) $headers->get('Range');
    }

    /** CacheableResponse#CacheStatus (Go's Header.Get: the first value of each header). */
    public static function isCacheable(Response $response): bool
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status > 399 || 304 === $status) {
            return false;
        }
        if (str_contains((string) $response->headers->get('Vary'), '*')) {
            return false;
        }

        $cacheControl = (string) $response->headers->get('Cache-Control');
        if (1 !== preg_match('/\bpublic\b/', $cacheControl) || 1 === preg_match('/\bno-cache\b/', $cacheControl)) {
            return false;
        }

        // Thruster's own spelling: `s-max-age`, so `s-maxage` falls through to `max-age`.
        if (1 !== preg_match('/\bs-max-age=(\d+)\b/', $cacheControl, $matches) && 1 !== preg_match('/\bmax-age=(\d+)\b/', $cacheControl, $matches)) {
            return false;
        }

        // strconv.Atoi fails beyond int64, which is not cacheable either.
        $maxAge = filter_var($matches[1], \FILTER_VALIDATE_INT);

        return \is_int($maxAge) && $maxAge > 0;
    }
}
