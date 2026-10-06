<?php

declare(strict_types=1);

namespace App\Tests\Unit\Push;

use App\Opengraph\HostResolver;
use App\Opengraph\PrivateNetworkGuard;
use App\Push\WebPushPool;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebPushPoolTest extends TestCase
{
    private Connection $db;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, int> endpoint => status */
    private array $statuses = [];

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE memberships (id INTEGER PRIMARY KEY, user_id INTEGER, unread_at TEXT)');
        $this->db->executeStatement('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER)');
        $this->db->executeStatement("INSERT INTO memberships (user_id, unread_at) VALUES (1, '2026-01-01 00:00:00'), (1, '2026-01-01 00:00:00'), (1, NULL), (2, NULL)");
        $this->db->executeStatement('INSERT INTO push_subscriptions (id, user_id) VALUES (10, 1), (11, 1), (12, 2), (13, 2), (14, 2)');
    }

    public function testDeliversToPublicPermittedEndpointsPinnedToTheCheckedAddress(): void
    {
        $sent = $this->pool()->queue(['title' => 'All Talk', 'body' => 'David: hi', 'path' => '/rooms/1'], [
            self::subscription(10, 1, 'https://fcm.googleapis.com/fcm/send/abc'),
            self::subscription(11, 1, 'https://evil.example/push'),            // not a push service
            self::subscription(12, 2, 'http://fcm.googleapis.com/fcm/send/x'),   // not https
            self::subscription(13, 2, 'https://internal.web.push.apple.com/x'),  // resolves to a private address
        ]);

        self::assertSame(1, $sent);
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://fcm.googleapis.com/fcm/send/abc', $request['url']);
        self::assertSame(['fcm.googleapis.com' => '142.250.1.1'], $request['options']['resolve']);
        $headers = implode("\n", $request['options']['headers']);
        self::assertStringContainsStringIgnoringCase('urgency: high', $headers);
        self::assertStringContainsStringIgnoringCase('content-encoding: aes128gcm', $headers);
        self::assertStringContainsStringIgnoringCase('authorization: vapid t=', $headers);
        self::assertStringContainsStringIgnoringCase('ttl: 2419200', $headers);
        self::assertSame(5, (int) $this->db->fetchOne('SELECT COUNT(*) FROM push_subscriptions'));
    }

    public function testNotificationMessage(): void
    {
        self::assertSame(
            '{"title":"All Talk","options":{"body":"David: <b> & é","icon":"/account/logo","data":{"path":"/rooms/1","badge":2}}}',
            $this->pool()->message(['title' => 'All Talk', 'body' => 'David: <b> & é', 'path' => '/rooms/1'], 1),
        );
    }

    public function testExpiredAndUnusableSubscriptionsAreDeleted(): void
    {
        $this->statuses = ['https://fcm.googleapis.com/fcm/send/gone' => 410, 'https://fcm.googleapis.com/fcm/send/missing' => 404, 'https://fcm.googleapis.com/fcm/send/busy' => 503];
        // Decodes, but isn't a P-256 point: an OpenSSL error in Rails, which invalidates it.
        $broken = self::subscription(13, 2, 'https://fcm.googleapis.com/fcm/send/broken');
        $broken['p256dh_key'] = 'AAAA';
        // Not Base64 at all: an ArgumentError in Rails, only logged; the subscription is kept.
        $garbled = self::subscription(14, 2, 'https://fcm.googleapis.com/fcm/send/garbled');
        $garbled['p256dh_key'] = '!!not base64!!';

        $this->pool()->queue(['title' => 't', 'body' => 'b', 'path' => '/rooms/1'], [
            self::subscription(10, 1, 'https://fcm.googleapis.com/fcm/send/gone'),
            self::subscription(11, 1, 'https://fcm.googleapis.com/fcm/send/missing'),
            self::subscription(12, 2, 'https://fcm.googleapis.com/fcm/send/busy'),
            $broken,
            $garbled,
        ]);

        self::assertSame([12, 14], array_map(intval(...), $this->db->fetchFirstColumn('SELECT id FROM push_subscriptions ORDER BY id')));
    }

    public function testPermittedHosts(): void
    {
        self::assertSame('fcm.googleapis.com', WebPushPool::permittedEndpointHost('https://fcm.googleapis.com/fcm/send/1'));
        self::assertSame('wns2-par02p.notify.windows.com', WebPushPool::permittedEndpointHost('https://WNS2-par02p.notify.windows.com/w/?token=1'));
        self::assertSame('web.push.apple.com', WebPushPool::permittedEndpointHost('https://web.push.apple.com:443/x'));
        self::assertNull(WebPushPool::permittedEndpointHost('https://web.push.apple.com:8443/x'));
        self::assertNull(WebPushPool::permittedEndpointHost('https://notweb.push.apple.com.evil.example/x'));
        self::assertNull(WebPushPool::permittedEndpointHost('https://fcm.googleapis.com/a b'));
        self::assertNull(WebPushPool::permittedEndpointHost(null));
    }

    private function pool(): WebPushPool
    {
        $resolver = new class implements HostResolver {
            public function resolve(string $hostname): array
            {
                return str_starts_with($hostname, 'internal.') ? ['10.0.0.1'] : ['142.250.1.1'];
            }
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => $this->statuses[$url] ?? 201]);
        });

        return new WebPushPool(
            $this->db,
            new PrivateNetworkGuard($resolver),
            $http,
            'BEYXTBB5_jNhNzXDmx5KEU55Vbbd-u--Lk9rM5OFQvUkPIBwZJ9QzAq0zdEzFw6yTV8cTriz_qYBVicY02_VxTQ',
            'qfXLHghuG1rSHZUVo9SscNRI-0EIHRbIrfeGCqbAwak',
        );
    }

    /** @return array{id: int, user_id: int, endpoint: string, p256dh_key: string, auth_key: string} */
    private static function subscription(int $id, int $userId, string $endpoint): array
    {
        static $keys = null;
        if (null === $keys) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
            self::assertNotFalse($key);
            $ec = openssl_pkey_get_details($key)['ec'] ?? [];
            $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $keys = [
                $encode("\x04".str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT)),
                $encode(random_bytes(16)),
            ];
        }

        return ['id' => $id, 'user_id' => $userId, 'endpoint' => $endpoint, 'p256dh_key' => $keys[0], 'auth_key' => $keys[1]];
    }
}
