<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Rails\CsrfToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ForgeryProtectionTest extends HttpTestCase
{
    private const string SECRET = 'Vb4gvo0Mh8Mxof-3dDjyp5L03ZQdVHkGzS-2Hm_gQGs';

    public function testPostWithoutTokenIs422(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session');

        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('422', (string) $response->getContent());
        // Raised before the action commits anything: no cookies, no version headers.
        self::assertSame([], $response->headers->getCookies());
        self::assertNull($response->headers->get('X-Version'));
    }

    public function testMaskedMetaTokenInHeaderPasses(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session', server: ['HTTP_X_CSRF_TOKEN' => CsrfToken::masked(self::SECRET)]);

        $this->assertSignedOut($client);
    }

    public function testPerFormTokenParamPasses(): void
    {
        $client = $this->signedInClient();
        $token = CsrfToken::perForm(self::SECRET, CsrfToken::normalizeActionPath('/session', '/'), 'delete');
        $client->request('POST', '/session', ['_method' => 'delete', 'authenticity_token' => $token]);

        $this->assertSignedOut($client);
    }

    public function testUrlEncodedBodyTokenPasses(): void
    {
        $client = $this->signedInClient();
        $client->request('POST', '/session', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: '_method=delete&authenticity_token='.rawurlencode(CsrfToken::masked(self::SECRET)));

        // The token is read from the raw body. (The test client doesn't parse it into the request
        // parameters, so `_method` isn't applied: sessions#create rejects the missing credentials.)
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCrossOriginIs422(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session', server: [
            'HTTP_X_CSRF_TOKEN' => CsrfToken::masked(self::SECRET),
            'HTTP_ORIGIN' => 'http://evil.example',
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSameOriginPasses(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session', server: [
            'HTTP_X_CSRF_TOKEN' => CsrfToken::masked(self::SECRET),
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $this->assertSignedOut($client);
    }

    public function testTokenFromAnotherSessionIs422(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session', server: ['HTTP_X_CSRF_TOKEN' => CsrfToken::masked(CsrfToken::generateSecret())]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testJsonErrorFormat(): void
    {
        $client = $this->signedInClient();
        $client->request('DELETE', '/session', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('{"status":422,"error":"Unprocessable Content"}', $client->getResponse()->getContent());
        self::assertSame('application/json; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testCsrfTokenIsGeneratedAndStoredOnFirstUse(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::respondAfterFilters($client, static fn (): Response => new Response(
            $client->getContainer()->get(\App\Http\Csrf::class)->maskedToken(),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        ));
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('GET', '/rooms/'.self::id('rooms.watercooler'));

        $session = self::railsSession($client->getResponse());
        self::assertIsString($session['_csrf_token']);
        self::assertTrue(CsrfToken::isValid($session['_csrf_token'], $client->getResponse()->getContent(), '/session', 'DELETE'));
    }

    /** sessions#destroy ran: the filters (CSRF included) let the request through. */
    private function assertSignedOut(KernelBrowser $client): void
    {
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/', $client->getResponse()->headers->get('Location'));
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM sessions WHERE token = ?', [self::DAVID_TOKEN]));
    }

    private function signedInClient(): KernelBrowser
    {
        $client = self::createClient();
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        self::setRawCookie($client, '_campfire_session', self::railsCookies()->writeEncrypted('_campfire_session', [
            'session_id' => str_repeat('a', 32),
            '_csrf_token' => self::SECRET,
        ], new \DateTimeImmutable('+20 years')));

        return $client;
    }
}
