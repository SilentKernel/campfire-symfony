<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\EventListener\ThrusterCacheListener;
use App\Http\RackResponseHeaderBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Thruster v0.1.23 CacheableResponse#CacheStatus and CacheHandler#shouldCacheRequest. */
final class ThrusterCacheListenerTest extends TestCase
{
    /** @return iterable<string, array{int, ?string, ?string, bool}> */
    public static function responses(): iterable
    {
        yield 'avatar' => [200, 'max-age=1800, public, stale-while-revalidate=604800', null, true];
        yield 'disk file' => [200, 'max-age=3600, public', null, true];
        yield 'redirect' => [302, 'max-age=60, public', null, true];
        yield 'not found' => [404, 'max-age=3600, public', null, false];
        yield 'not modified' => [304, 'max-age=1800, public', null, false];
        yield 'private' => [200, 'max-age=0, private, must-revalidate', null, false];
        yield 'no cache' => [200, 'public, no-cache, max-age=60', null, false];
        yield 'zero max-age' => [200, 'public, max-age=0', null, false];
        yield 'no max-age' => [200, 'public', null, false];
        yield 'thruster s-max-age' => [200, 'public, s-max-age=60', null, true];
        yield 's-maxage only' => [200, 'public, s-maxage=60', null, false];
        yield 'vary star' => [200, 'max-age=60, public', '*', false];
        yield 'vary accept' => [200, 'max-age=60, public', 'Accept', true];
        yield 'nothing' => [200, null, null, false];
    }

    #[DataProvider('responses')]
    public function testCacheStatus(int $status, ?string $cacheControl, ?string $vary, bool $cacheable): void
    {
        self::assertSame($cacheable, ThrusterCacheListener::isCacheable(self::response($status, $cacheControl, $vary)));
    }

    /** @return iterable<string, array{string, array<string, string>, bool}> */
    public static function requests(): iterable
    {
        yield 'get' => ['GET', [], true];
        yield 'head' => ['HEAD', [], true];
        yield 'post' => ['POST', [], false];
        yield 'range' => ['GET', ['HTTP_RANGE' => 'bytes=0-1'], false];
        yield 'upgrade' => ['GET', ['HTTP_CONNECTION' => 'Upgrade'], false];
        yield 'websocket' => ['GET', ['HTTP_UPGRADE' => 'websocket'], false];
    }

    /** @param array<string, string> $server */
    #[DataProvider('requests')]
    public function testShouldCacheRequest(string $method, array $server, bool $cached): void
    {
        self::assertSame($cached, ThrusterCacheListener::shouldCacheRequest(Request::create('/x', $method, server: $server)));
    }

    public function testSetCookieIsDroppedFromPubliclyCacheableResponses(): void
    {
        $public = $this->dispatch(Request::create('/account/logo'), self::response(200, 'max-age=300, public, stale-while-revalidate=604800'));
        self::assertSame([], $public->headers->getCookies());
        self::assertFalse($public->headers->has('Set-Cookie'));

        $private = $this->dispatch(Request::create('/rooms/1'), self::response(200, 'max-age=0, private, must-revalidate'));
        self::assertCount(2, $private->headers->getCookies());

        $range = $this->dispatch(Request::create('/rails/active_storage/disk/x/moon.jpg', server: ['HTTP_RANGE' => 'bytes=2-5']), self::response(206, 'max-age=3600, public'));
        self::assertCount(2, $range->headers->getCookies());
    }

    private function dispatch(Request $request, Response $response): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new ThrusterCacheListener())(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private static function response(int $status, ?string $cacheControl, ?string $vary = null): Response
    {
        $response = new Response('x', $status);
        $headers = RackResponseHeaderBag::from($response->headers, $cacheControl);
        if (null !== $vary) {
            $headers->set('Vary', $vary);
        }
        $headers->setCookie(Cookie::create('_campfire_session', 'a'));
        $headers->setCookie(Cookie::create('session_token', 'b'));
        $response->headers = $headers;

        return $response;
    }
}
