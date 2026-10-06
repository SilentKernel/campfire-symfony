<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messages;

use App\Cable\RecordingBroadcaster;
use App\Rails\CsrfToken;
use App\Rails\RailsCookies;
use App\Tests\Functional\Http\HttpTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs seed users in and drives the message, boost, search and unfurl endpoints with one
 * kernel (so the RecordingBroadcaster and the fragment cache see every request), the clock
 * frozen at the seed's "now".
 */
abstract class MessagesTestCase extends HttpTestCase
{
    public const string NOW = '2026-03-02T16:00:00Z';

    /** Where the Rails pages in fixtures/ were captured (campfire-reference:app, as David). */
    public const string REFERENCE_HOST = 'http://127.0.0.1:3255';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('CAMPFIRE_FROZEN_TIME', self::NOW);
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

    /** @param array<string, string> $headers HTTP_* server keys */
    protected function get(string $path, array $headers = []): Response
    {
        $this->client->request('GET', $path, [], [], $headers + ['HTTP_ACCEPT' => '*/*']);

        return $this->client->getResponse();
    }

    /**
     * A form submission carrying the session's authenticity token.
     *
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $files
     * @param array<string, string> $headers
     */
    protected function submit(string $method, string $path, array $params = [], array $headers = [], array $files = []): Response
    {
        if (!\in_array($method, ['GET', 'POST'], true)) {
            $params['_method'] = strtolower($method);
        }
        $params['authenticity_token'] = $this->csrfToken();
        $this->client->request('GET' === $method ? 'GET' : 'POST', $path, $params, $files, $headers + ['HTTP_ACCEPT' => '*/*']);

        return $this->client->getResponse();
    }

    /** The session's masked global CSRF token (loads a page first to create the session). */
    protected function csrfToken(): string
    {
        $session = $this->sessionData();
        if (!isset($session['_csrf_token'])) {
            $this->client->request('GET', '/searches');
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

    protected function fetchValue(string $sql, mixed ...$params): mixed
    {
        return $this->connection()->fetchOne($sql, $params);
    }

    /** A Rails page from fixtures/, normalized like ours. */
    protected static function railsFixture(string $name): string
    {
        return self::normalize((string) file_get_contents(__DIR__.'/fixtures/'.$name));
    }

    /**
     * Byte-level normalization for comparing pages: authenticity tokens blanked, asset digests
     * dropped (Propshaft's and AssetMapper's differ), the reference host replaced by ours.
     */
    protected static function normalize(string $html): string
    {
        $html = str_replace(self::REFERENCE_HOST, 'http://localhost', $html);
        $html = (string) preg_replace('/(name="authenticity_token" value=")[^"]*"/', '$1TOKEN"', $html);
        $html = (string) preg_replace('/(name="csrf-token" content=")[^"]*"/', '$1TOKEN"', $html);

        return (string) preg_replace('#/assets/([^"\s]+?)-[A-Za-z0-9_-]{7,8}\.(\w+)#', '/assets/$1.$2', $html);
    }

    /** The <main> element of a page. */
    protected static function main(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');

        return false === $start || false === $end ? $html : substr($html, $start, $end - $start);
    }

    /** Asserts two documents are equal, reporting the first differing line. */
    protected static function assertSameHtml(string $expected, string $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            self::assertTrue(true);

            return;
        }
        $e = explode("\n", $expected);
        $a = explode("\n", $actual);
        foreach ($e as $i => $line) {
            if (($a[$i] ?? null) !== $line) {
                self::fail(\sprintf("%s\nFirst difference at line %d:\n- %s\n+ %s", $message, $i + 1, $line, $a[$i] ?? '(missing)'));
            }
        }
        self::fail(\sprintf("%s\nOur output has %d extra lines, starting with:\n+ %s", $message, \count($a) - \count($e), $a[\count($e)] ?? ''));
    }
}
