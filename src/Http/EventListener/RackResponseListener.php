<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use App\Http\Cookies;
use App\Http\Flash;
use App\Http\Pipeline;
use App\Http\RackResponseHeaderBag;
use App\Http\RailsSession;
use App\Http\RawCookie;
use App\Http\Ssl;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * The Rack middleware Rails wraps every response in, innermost first:
 *
 * - Rack::ETag ("no-cache" fallback) and Rack::ConditionalGet, before Symfony's prepare() strips
 *   HEAD bodies (Rack::Head is outside them);
 * - then, last: the controller's commit_flash, the cookie session store's commit, the cookie jar
 *   (ActionDispatch::Cookies) and ActionDispatch::SSL (HSTS, "; secure" on every cookie).
 *
 * Error pages are rendered outside all of that but SSL (ShowExceptions): no ETag, Cache-Control,
 * cookies, version headers or `action_dispatch.default_headers`. ActionDispatch::RequestId and
 * Rack::Runtime wrap everything, error pages included (public/ files are Caddy's, without them).
 */
final readonly class RackResponseListener
{
    public const string DEFAULT_CACHE_CONTROL = 'max-age=0, private, must-revalidate';

    /** `config.action_dispatch.default_headers` (Rails 7.0+ defaults, unchanged by the reference). */
    public const array DEFAULT_HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '0',
        'X-Content-Type-Options' => 'nosniff',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function __construct(
        private Flash $flash,
        private RailsSession $session,
        private Cookies $cookies,
        private Ssl $ssl,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 8)]
    public function conditionalGet(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || Pipeline::isException($request)) {
            return;
        }

        $response = $event->getResponse();
        $request->attributes->set(Pipeline::CONTENT_TYPE, $response->headers->get('Content-Type'));
        $headers = $response->headers;

        // Rack::ETag
        $digest = null;
        $status = $response->getStatusCode();
        if ((200 === $status || 201 === $status) && self::isBuffered($response) && !$headers->has('ETag') && !$headers->has('Last-Modified')) {
            $body = (string) $response->getContent();
            if ('' !== $body) {
                $digest = substr(hash('sha256', $body), 0, 32);
                $headers->set('ETag', \sprintf('W/"%s"', $digest));
            }
        }
        $cacheControl = RackResponseHeaderBag::hasExplicitCacheControl($headers)
            ? $headers->get('Cache-Control')
            : (null !== $digest ? self::DEFAULT_CACHE_CONTROL : 'no-cache');
        $request->attributes->set(Pipeline::CACHE_CONTROL, $cacheControl);

        // Rack::ConditionalGet
        if (200 === $status && \in_array($request->getMethod(), ['GET', 'HEAD'], true) && self::isFresh($request->headers->get('If-None-Match'), $request->headers->get('If-Modified-Since'), $response)) {
            $response->setStatusCode(304);
            $response->setContent('');
            $headers->remove('Content-Type');
            $headers->remove('Content-Length');
            $request->attributes->set(Pipeline::CONTENT_TYPE, null);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -2048)]
    public function commit(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $exception = Pipeline::isException($request);

        if (!$exception) {
            foreach (self::DEFAULT_HEADERS as $name => $value) {
                if (!$response->headers->has($name)) {
                    $response->headers->set($name, $value);
                }
            }
            foreach ($request->attributes->get(Pipeline::VERSION_HEADERS, []) as $name => $value) {
                $response->headers->set($name, $value);
            }

            // Response::prepare() appends "; charset=UTF-8" to text/* types; Rails' `head` has none.
            $contentType = $request->attributes->get(Pipeline::CONTENT_TYPE);
            if (\is_string($contentType) && $response->headers->has('Content-Type') && !str_contains(strtolower($contentType), 'charset')) {
                $response->headers->set('Content-Type', $contentType);
            }

            $this->flash->commit();
            $this->session->commit();
            foreach ($this->cookies->responseCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        if ($this->ssl->enabled) {
            if (!$response->headers->has('Strict-Transport-Security')) {
                $response->headers->set('Strict-Transport-Security', Ssl::HSTS);
            }
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->setCookie(self::secure($cookie));
            }
        }

        $response->headers->set('X-Request-Id', self::requestId($request->headers->get('X-Request-Id')));
        $started = $request->server->get('REQUEST_TIME_FLOAT');
        if (!$response->headers->has('X-Runtime') && is_numeric($started)) {
            $response->headers->set('X-Runtime', \sprintf('%0.6f', max(0.0, microtime(true) - (float) $started)));
        }

        $cacheControl = $exception ? null : $request->attributes->get(Pipeline::CACHE_CONTROL);
        $response->headers = RackResponseHeaderBag::from($response->headers, \is_string($cacheControl) ? $cacheControl : null);
    }

    /** ActionDispatch::RequestId#make_request_id */
    private static function requestId(?string $header): string
    {
        if (null === $header || '' === trim($header)) {
            return Uuid::v4()->toRfc4122();
        }

        return substr((string) preg_replace('/[^\w\-@]/', '', $header), 0, 255);
    }

    /** ActionDispatch::SSL#flag_cookies_as_secure! */
    private static function secure(Cookie $cookie): Cookie
    {
        $header = (string) $cookie;
        if (1 === preg_match('/;\s*secure\s*(;|$)/i', $header)) {
            return $cookie;
        }

        return $cookie instanceof RawCookie
            ? $cookie->withHeader($header.'; secure')
            : new RawCookie($cookie->getName(), $header.'; secure', $cookie->getPath(), $cookie->getDomain());
    }

    /** The body is a buffered string (Rack's `body.respond_to?(:to_ary)`), not a stream. */
    private static function isBuffered(Response $response): bool
    {
        return !$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse && false !== $response->getContent();
    }

    private static function isFresh(?string $noneMatch, ?string $modifiedSince, Response $response): bool
    {
        if (null !== $noneMatch) {
            return $response->headers->get('ETag') === $noneMatch;
        }
        if (null !== $modifiedSince && \strlen($modifiedSince) >= 16 && null !== $lastModified = $response->headers->get('Last-Modified')) {
            $since = \DateTimeImmutable::createFromFormat(\DATE_RFC2822, $modifiedSince) ?: date_create_immutable($modifiedSince);
            $last = \strlen($lastModified) >= 16 ? (\DateTimeImmutable::createFromFormat(\DATE_RFC2822, $lastModified) ?: date_create_immutable($lastModified)) : false;

            return false !== $since && false !== $last && $since >= $last;
        }

        return false;
    }
}
