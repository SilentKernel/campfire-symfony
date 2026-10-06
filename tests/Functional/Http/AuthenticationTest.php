<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use Symfony\Component\HttpFoundation\Response;

final class AuthenticationTest extends HttpTestCase
{
    public function testUnauthenticatedRequestRedirectsToSignInAndRemembersTheUrl(): void
    {
        $client = self::createClient();
        $room = self::id('rooms.watercooler');
        $client->request('GET', "/rooms/{$room}?a=1");

        $response = $client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/session/new', $response->headers->get('Location'));
        self::assertSame('test', $response->headers->get('X-Version'));
        self::assertSame('test', $response->headers->get('X-Rev'));
        self::assertSame('no-cache', $response->headers->get('Cache-Control'));
        self::assertSame('', $response->getContent());

        // An encrypted _campfire_session Rails can read.
        $session = self::railsSession($response);
        self::assertSame("http://localhost/rooms/{$room}?a=1", $session['return_to_after_authenticating']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $session['session_id']);
        self::assertMatchesRegularExpression('/\A_campfire_session=[^;]+; path=\/; expires=\w{3}, \d{2} \w{3} '.((int) gmdate('Y') + 20).' \d{2}:\d{2}:\d{2} GMT; httponly; samesite=lax\z/', (string) self::setCookie($response, '_campfire_session'));
        self::assertNull(self::setCookie($response, 'session_token'));
    }

    public function testRailsSignedSessionCookieAuthenticates(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response('<p>ok</p>', 200, ['Content-Type' => 'text/html; charset=utf-8']));
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);

        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'), server: ['HTTP_USER_AGENT' => 'Test Agent', 'REMOTE_ADDR' => '10.1.2.3']);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('test', $response->headers->get('X-Version'));

        // cookies.signed.permanent[:session_token] is re-set on every authenticated request.
        $token = self::railsCookies()->readSigned('session_token', self::cookieValue($response, 'session_token'));
        self::assertSame(self::DAVID_TOKEN, $token);
        self::assertStringEndsWith('; httponly; samesite=lax', (string) self::setCookie($response, 'session_token'));

        // Session#resume: the seed's last activity is long past, so it is refreshed.
        $row = $this->connection()->fetchAssociative('SELECT user_agent, ip_address, last_active_at FROM sessions WHERE token = ?', [self::DAVID_TOKEN]);
        self::assertSame('Test Agent', $row['user_agent']);
        self::assertSame('10.1.2.3', $row['ip_address']);
        self::assertNotSame('2026-03-02 14:00:00', $row['last_active_at']);
    }

    public function testActionErrorsKeepTheirStatusWithoutCookiesOrVersionHeaders(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => throw new \Symfony\Component\HttpKernel\Exception\HttpException(501));
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        // The filters passed and the action raised: ShowExceptions renders outside the cookie
        // middleware and the controller's headers.
        $response = $client->getResponse();
        self::assertSame(501, $response->getStatusCode());
        self::assertNull($response->headers->get('X-Version'));
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Cache-Control'));
    }

    public function testForgedCookieRedirectsToSignIn(): void
    {
        $client = self::createClient();
        self::setRawCookie($client, 'session_token', self::FORGED_COOKIE);
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/session/new', $client->getResponse()->headers->get('Location'));
    }

    public function testUnknownSessionTokenRedirectsToSignIn(): void
    {
        $client = self::createClient();
        self::setRawCookie($client, 'session_token', self::railsCookies()->writeSigned('session_token', 'nope', new \DateTimeImmutable('+1 year')));
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    public function testBotKeyIsDeniedOnRoutesWithoutBotAccess(): void
    {
        $client = self::createClient();
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler').'?bot_key='.self::labels('bot_keys.bender'));

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
        self::assertSame('test', $response->headers->get('X-Version'));
    }

    public function testBotKeyAuthenticatesBotRoutesWithoutCsrf(): void
    {
        $client = self::createClient();
        $client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/'.self::labels('bot_keys.bender').'/messages', content: 'Hello');

        // Filters passed (no 403, no 422): Messages::ByBotsController#create answers.
        self::assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testInvalidBotKeyOnBotRouteRedirects(): void
    {
        $client = self::createClient();
        $client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/'.self::id('users.bender').'-wrong/messages', content: 'Hello');

        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    public function testRequireUnauthenticatedAccessRedirectsSignedInUsersToRoot(): void
    {
        $client = self::createClient();
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/join/'.self::labels('join_codes.signal'));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/', $client->getResponse()->headers->get('Location'));
    }

    public function testRequireUnauthenticatedAccessLetsVisitorsThrough(): void
    {
        $client = self::createClient();
        $client->request('GET', '/join/'.self::labels('join_codes.signal'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testAllowUnauthenticatedAccessDoesNotRestoreTheSession(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $current = null;
        self::respondAfterFilters($client, static function () use ($client, &$current): Response {
            $current = $client->getContainer()->get(\App\Http\Current::class)->user();

            return new Response('new');
        });
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/session/new');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($current);
        self::assertNull(self::setCookie($client->getResponse(), 'session_token'));
    }
}
