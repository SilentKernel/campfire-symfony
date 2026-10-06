<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Rails\CsrfToken;
use App\Security\SecureToken;
use App\Tests\Functional\Http\HttpTestCase;
use App\Tests\Support\SeedDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers for the identity and administration tests: signing in as a seed user (a session row
 * plus the signed session_token cookie, as Rails would set it) and a Rails session carrying a
 * known CSRF secret, so forms can be posted without fetching a page first.
 */
abstract class IdentityTestCase extends HttpTestCase
{
    protected const string RAILS_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private ?string $csrfSecret = null;

    protected function client(): KernelBrowser
    {
        $client = self::createClient([], ['HTTP_USER_AGENT' => self::RAILS_CHROME]);
        $this->csrfSecret = CsrfToken::generateSecret();
        self::setRawCookie($client, '_campfire_session', self::railsCookies()->writeEncrypted('_campfire_session', [
            'session_id' => bin2hex(random_bytes(16)),
            '_csrf_token' => $this->csrfSecret,
        ]));

        return $client;
    }

    /** Signs $client in as the seed user labelled $label ("david", "kevin"…), returning the session token. */
    protected function signIn(KernelBrowser $client, string $label, string $ipAddress = '203.0.113.50'): string
    {
        $token = SecureToken::generate();
        SeedDatabase::connect($this->storagePath)->insert('sessions', [
            'user_id' => self::id('users.'.$label),
            'token' => $token,
            'ip_address' => $ipAddress,
            'user_agent' => self::RAILS_CHROME,
            'last_active_at' => gmdate('Y-m-d H:i:s.000000'),
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
            'updated_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);
        self::setRawCookie($client, 'session_token', self::railsCookies()->writeSigned('session_token', $token));

        return $token;
    }

    /** The masked authenticity token for the client's Rails session. */
    protected function csrf(): string
    {
        return CsrfToken::masked($this->csrfSecret ?? throw new \LogicException('Create the client with client() first.'));
    }

    /**
     * A form submission with the authenticity token (`_method` for PATCH/PUT/DELETE, as Rails
     * forms send them).
     *
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $files
     * @param array<string, string> $server
     */
    protected function submit(KernelBrowser $client, string $method, string $uri, array $params = [], array $files = [], array $server = []): Response
    {
        $params['authenticity_token'] ??= $this->csrf();
        if ('POST' !== $method) {
            $params['_method'] = strtolower($method);
        }
        $client->request('POST', $uri, $params, $files, $server);

        return $client->getResponse();
    }

    protected function get(KernelBrowser $client, string $uri, array $server = []): Response
    {
        $client->request('GET', $uri, [], [], $server);

        return $client->getResponse();
    }

    /** Empties every Rails table of the test's database copy (a fresh install: no account, no users). */
    protected function emptyDatabase(): void
    {
        $connection = SeedDatabase::connect($this->storagePath);
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('schema_migrations', 'ar_internal_metadata', 'sqlite_sequence') AND name NOT LIKE 'message_search_index_%'") as $table) {
            $connection->executeStatement(\sprintf('DELETE FROM "%s"', $table));
        }
        $connection->close();
    }

    /** @return array<string, mixed>|false */
    protected function row(string $sql, array $params = []): array|false
    {
        return SeedDatabase::connect($this->storagePath)->fetchAssociative($sql, $params);
    }

    protected function value(string $sql, array $params = []): mixed
    {
        return SeedDatabase::connect($this->storagePath)->fetchOne($sql, $params);
    }
}
