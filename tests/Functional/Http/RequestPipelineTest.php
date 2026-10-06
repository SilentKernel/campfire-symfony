<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Cable\RecordingBroadcaster;
use App\Http\Current;
use App\Http\Flash;
use App\Http\RailsSession;
use Symfony\Component\HttpFoundation\Response;

final class RequestPipelineTest extends HttpTestCase
{
    public function testBannedIpCannotPost(): void
    {
        $client = self::createClient();
        $client->request('POST', '/session', server: ['REMOTE_ADDR' => (string) self::labels('ips.banned')]);

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('test', $response->headers->get('X-Version'));
    }

    public function testBannedIpCanStillGet(): void
    {
        $client = self::createClient();
        $client->request('GET', '/session/new', server: ['REMOTE_ADDR' => (string) self::labels('ips.banned')]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testHealthCheckIsNotAnApplicationController(): void
    {
        $client = self::createClient();
        $client->request('GET', '/up');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->headers->get('X-Version'));
        self::assertSame([], $response->headers->getCookies());
        self::assertMatchesRegularExpression('/\AW\/"[0-9a-f]{32}"\z/', (string) $response->headers->get('ETag'));
    }

    public function testEtagAndConditionalGet(): void
    {
        $client = self::createClient();
        $client->request('GET', '/up');
        $response = $client->getResponse();
        $etag = (string) $response->headers->get('ETag');
        self::assertSame('W/"'.substr(hash('sha256', (string) $response->getContent()), 0, 32).'"', $etag);
        self::assertSame('max-age=0, private, must-revalidate', $response->headers->get('Cache-Control'));

        $client->request('GET', '/up', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        $response = $client->getResponse();
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('Content-Type'));
        self::assertSame($etag, $response->headers->get('ETag'));

        $client->request('GET', '/up', server: ['HTTP_IF_NONE_MATCH' => 'W/"other"']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testEmptyBodiesGetNoCache(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response('', 200));
        $client->request('GET', '/session/new');

        self::assertFalse($client->getResponse()->headers->has('ETag'));
        self::assertSame('no-cache', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testExplicitCacheControlIsKept(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response('x', 200, ['Cache-Control' => 'public, max-age=60']));
        $client->request('GET', '/session/new');

        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testRoutingErrorsUseThePublicPages(): void
    {
        $client = self::createClient();
        $client->request('GET', '/no/such/page');

        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('</html>', (string) $response->getContent());
        self::assertFalse($response->headers->has('Cache-Control'));

        // No route for the verb is a RoutingError too.
        $client->request('PUT', '/up');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/no/such/page', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame('{"status":404,"error":"Not Found"}', $client->getResponse()->getContent());
    }

    public function testRailsDefaultHeadersRequestIdAndRuntime(): void
    {
        $client = self::createClient();
        $client->request('GET', '/up', server: ['HTTP_X_REQUEST_ID' => 'abc-12@3!<x>']);

        $response = $client->getResponse();
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('abc-12@3x', $response->headers->get('X-Request-Id'));
        self::assertMatchesRegularExpression('/\A\d+\.\d{6}\z/', (string) $response->headers->get('X-Runtime'));

        // Error pages are rendered outside the controller: no default headers, but a request id.
        $client->request('GET', '/no/such/page');
        $response = $client->getResponse();
        self::assertFalse($response->headers->has('X-Frame-Options'));
        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $response->headers->get('X-Request-Id'));
    }

    public function testPathsAreRecognizedLikeJourney(): void
    {
        $client = self::createClient();

        // Normalized, not redirected.
        $client->request('GET', 'http://localhost//up//');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', '/session/new/');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // An escaped slash stays inside its segment, and the parameter is unescaped
        // (users#new then answers 404 for the wrong join code).
        $client->request('GET', '/join/a%2Fb');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('a/b', $client->getRequest()->attributes->get('join_code'));
    }

    public function testHaltIsAResponseNotALoggedError(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $logger = $client->getContainer()->get('monolog.logger.request');
        \assert($logger instanceof \Monolog\Logger);
        $records = new \Monolog\Handler\TestHandler();
        $logger->pushHandler($records);
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);

        // RoomsController#set_room: `redirect_to root_url, alert: "Room not found or inaccessible"`.
        $client->request('GET', '/rooms/1');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('test', $client->getResponse()->headers->get('X-Version'));
        self::assertFalse($records->hasRecords(\Monolog\Level::Error) || $records->hasRecords(\Monolog\Level::Critical), 'Halt was logged as an error.');
    }

    public function testActionNotFoundRunsNoFilters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/rooms/new');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testErrorsWithoutAPublicPageAreEmpty(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => throw new \Symfony\Component\HttpKernel\Exception\HttpException(501));
        $client->request('GET', '/session/new');

        $response = $client->getResponse();
        self::assertSame(501, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('0', $response->headers->get('Content-Length'));
    }

    public function testSslIsAssumedUnlessDisabled(): void
    {
        $this->setEnv('DISABLE_SSL', '');
        $client = self::createClient();
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        $response = $client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://localhost/session/new', $response->headers->get('Location'));
        self::assertSame('max-age=63072000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
        // The session store's own `secure` option: Rack puts it before httponly.
        self::assertStringEndsWith('GMT; secure; httponly; samesite=lax', (string) self::setCookie($response, '_campfire_session'));
        self::assertSame('https://localhost/rooms/'.self::id('rooms.watercooler'), self::railsSession($response)['return_to_after_authenticating']);

        // Error pages get HSTS too (SSL wraps ShowExceptions).
        $client->request('GET', '/no/such/page');
        self::assertSame('max-age=63072000; includeSubDomains', $client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function testSslFlagsOtherCookiesSecureAtTheEnd(): void
    {
        $this->setEnv('DISABLE_SSL', '');
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response('ok'));
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringEndsWith('GMT; httponly; samesite=lax; secure', (string) self::setCookie($client->getResponse(), 'session_token'));
    }

    public function testFlashIsShownOnTheNextRequestOnly(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        $step = 0;
        $seen = [];
        self::respondAfterFilters($client, static function () use ($container, &$step, &$seen): Response {
            $flash = $container->get(Flash::class);
            $seen[] = $flash->get('notice');
            if (0 === $step++) {
                $flash->set('notice', 'Saved');
                $flash->now('alert', 'Only now');
            }

            return new Response('ok');
        });

        $client->request('GET', '/session/new');
        $stored = self::railsSession($client->getResponse());
        self::assertSame(['discard' => [], 'flashes' => ['notice' => 'Saved']], $stored['flash']);

        $client->request('GET', '/session/new');
        self::assertArrayNotHasKey('flash', self::railsSession($client->getResponse()));

        $client->request('GET', '/session/new');
        self::assertSame([null, 'Saved', null], $seen);
    }

    public function testRailsSessionWrittenOnlyWhenLoadedOrPresent(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response('ok'));

        // Nothing touched the session and the request had none: no cookie.
        $client->request('GET', '/session/new');
        self::assertNull(self::setCookie($client->getResponse(), '_campfire_session'));

        // A request carrying a session gets it back with a fresh expiry (expire_after).
        self::setRawCookie($client, '_campfire_session', self::railsCookies()->writeEncrypted('_campfire_session', ['session_id' => str_repeat('b', 32), 'x' => '1'], new \DateTimeImmutable('+1 day')));
        $client->request('GET', '/session/new');
        self::assertSame(['session_id' => str_repeat('b', 32), 'x' => '1'], self::railsSession($client->getResponse()));
    }

    public function testWorkerModeDoesNotLeakRequestState(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        $users = [];
        self::respondAfterFilters($client, static function () use ($container, &$users): Response {
            $users[] = $container->get(Current::class)->user()?->getName();

            return new Response('ok');
        });

        // Jason gets a session of his own.
        $token = 'JasonToken1234567890abcd';
        $this->connection()->insert('sessions', ['user_id' => self::id('users.jason'), 'token' => $token, 'last_active_at' => '2026-03-02 15:00:00', 'created_at' => '2026-03-02 15:00:00', 'updated_at' => '2026-03-02 15:00:00']);
        $jason = self::railsCookies()->writeSigned('session_token', $token, new \DateTimeImmutable('+1 year'));

        $room = '/rooms/'.self::id('rooms.watercooler');
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', $room);
        self::setRawCookie($client, 'session_token', $jason);
        $client->request('GET', $room);
        $client->getCookieJar()->clear();
        $client->request('GET', $room);

        self::assertSame(['David', 'Jason'], $users);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        // The worker resets services between requests (kernel.reset).
        $container->get('services_resetter')->reset();
        self::assertNull($container->get(Current::class)->user());
        self::assertFalse($container->get(RailsSession::class)->isLoaded());
    }

    public function testSignOutHelpersEndTheSession(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        self::respondAfterFilters($client, static function () use ($container): Response {
            $container->get(\App\Security\Authentication::class)->terminateCurrentSession();

            return new Response('', 302, ['Location' => 'http://localhost/']);
        });
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        $response = $client->getResponse();
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM sessions WHERE token = ?', [self::DAVID_TOKEN]));
        self::assertSame('session_token=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; samesite=lax', self::setCookie($response, 'session_token'));
        $broadcaster = $container->get(RecordingBroadcaster::class);
        self::assertSame([['userId' => self::id('users.david'), 'reconnect' => true]], $broadcaster->disconnects);
        $session = self::railsSession($response);
        self::assertSame(['session_id'], array_keys($session));
    }

    public function testStartNewSessionFor(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        self::respondAfterFilters($client, static function () use ($container): Response {
            $jason = $container->get(\App\Repository\UserRepository::class)->find(self::id('users.jason'));
            $container->get(\App\Security\Authentication::class)->startNewSessionFor($jason);

            return new Response('ok');
        });
        $client->request('GET', '/session/new', server: ['HTTP_USER_AGENT' => 'UA']);

        $token = self::railsCookies()->readSigned('session_token', self::cookieValue($client->getResponse(), 'session_token'));
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/\A[1-9A-HJ-NP-Za-km-z]{24}\z/', $token);
        $row = $this->connection()->fetchAssociative('SELECT user_id, user_agent, ip_address FROM sessions WHERE token = ?', [$token]);
        self::assertSame(['user_id' => self::id('users.jason'), 'user_agent' => 'UA', 'ip_address' => '127.0.0.1'], $row);
    }
}
