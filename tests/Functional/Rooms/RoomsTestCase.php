<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\RecordingBroadcaster;
use App\Rails\CsrfToken;
use App\Rails\RailsCookies;
use App\Tests\Functional\Http\HttpTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs seed users in (a fresh session row and its signed `session_token` cookie) and drives
 * the room pages. The client keeps one kernel, so the RecordingBroadcaster sees every request.
 */
abstract class RoomsTestCase extends HttpTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:00:00Z');
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
    }

    /** Signs in as the seed user with this label (`users.david`). */
    protected function signIn(string $user): void
    {
        $token = bin2hex(random_bytes(12));
        $this->connection()->insert('sessions', [
            'created_at' => '2026-03-02 15:00:00', 'updated_at' => '2026-03-02 15:00:00', 'last_active_at' => '2026-03-02 15:59:00',
            'token' => $token, 'user_id' => self::id($user), 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'ip_address' => '127.0.0.1',
        ]);
        $this->client->getCookieJar()->clear();
        self::setRawCookie($this->client, 'session_token', self::railsCookies()->writeSigned('session_token', $token, new \DateTimeImmutable('+1 year')));
    }

    /**
     * @param array<string, string> $headers HTTP_* server keys
     */
    protected function get(string $path, array $headers = []): Response
    {
        $this->client->request('GET', $path, [], [], $headers + ['HTTP_ACCEPT' => '*/*']);

        return $this->client->getResponse();
    }

    /**
     * A form submission with a valid per-form authenticity token for $path.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    protected function submit(string $method, string $path, array $params = [], array $headers = []): Response
    {
        $httpMethod = 'GET' === $method ? 'GET' : 'POST';
        if (!\in_array($method, ['GET', 'POST'], true)) {
            $params['_method'] = strtolower($method);
        }
        $params['authenticity_token'] = $this->csrfToken();
        $body = http_build_query($params, '', '&', \PHP_QUERY_RFC1738);
        $body = (string) preg_replace('/%5B\d+%5D/', '%5B%5D', $body);
        // The parsed `_method` lets Symfony override the method; Params reads the raw body.
        $parsed = isset($params['_method']) ? ['_method' => $params['_method']] : [];
        $this->client->request($httpMethod, $path, $parsed, [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $headers, $body);

        return $this->client->getResponse();
    }

    /** A request without any token (CSRF checks). */
    protected function submitWithoutToken(string $method, string $path): Response
    {
        $this->client->request($method, $path);

        return $this->client->getResponse();
    }

    /** The session's global CSRF token, masked (loads a page to create the session). */
    protected function csrfToken(): string
    {
        $session = $this->sessionData();
        if (!isset($session['_csrf_token'])) {
            $this->get('/users/me/sidebar');
            $session = $this->sessionData();
        }

        return CsrfToken::masked((string) $session['_csrf_token']);
    }

    /** @return array<string, mixed> */
    protected function sessionData(): array
    {
        $cookie = $this->client->getCookieJar()->get('_campfire_session');
        if (null === $cookie) {
            return [];
        }
        $data = self::railsCookies()->readEncrypted('_campfire_session', RailsCookies::unescape($cookie->getRawValue()));

        return \is_array($data) ? $data : [];
    }

    protected function broadcaster(): RecordingBroadcaster
    {
        $broadcaster = $this->client->getContainer()->get(\App\Cable\Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);

        return $broadcaster;
    }

    protected function crawler(Response $response): Crawler
    {
        return new Crawler((string) $response->getContent(), 'http://localhost/');
    }

    protected static function assertRedirectsTo(string $location, Response $response, int $status = 302): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($location, $response->headers->get('Location'));
    }

    protected function fetchValue(string $sql, mixed ...$params): mixed
    {
        return $this->connection()->fetchOne($sql, $params);
    }
}
