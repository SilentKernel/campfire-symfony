<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Job\RemoveBannedContentJob;
use App\Tests\Functional\Identity\IdentityTestCase;
use App\Tests\Functional\Identity\SeedHelper;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Users::BansController and User::Bannable. */
final class BansTest extends IdentityTestCase
{
    public function testBanBlocksTheSessionsAddressesAndSignsTheUserOut(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $kevin = self::id('users.kevin');
        $connection = \App\Tests\Support\SeedDatabase::connect($this->storagePath);
        foreach (['198.51.100.20', '198.51.100.20', '2001:4860:4860::8888', ''] as $ip) {
            $connection->insert('sessions', ['user_id' => $kevin, 'token' => bin2hex(random_bytes(12)), 'ip_address' => $ip, 'last_active_at' => '2026-03-02 16:00:00', 'created_at' => '2026-03-02 16:00:00', 'updated_at' => '2026-03-02 16:00:00']);
        }

        $response = $this->submit($client, 'POST', "/users/{$kevin}/ban");

        self::assertSame(302, $response->getStatusCode());
        self::assertSame("http://localhost/users/{$kevin}", $response->headers->get('Location'));
        self::assertSame(2, (int) $this->value('SELECT status FROM users WHERE id = ?', [$kevin]));
        self::assertSame(['198.51.100.20', '2001:4860:4860::8888'], array_column(SeedHelper::rows($this->storagePath, 'SELECT ip_address FROM bans WHERE user_id = ? ORDER BY id', [$kevin]), 'ip_address'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$kevin]));
        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);
        self::assertContains([$kevin, false], array_map(static fn (array $d): array => array_values($d), $broadcaster->disconnects));

        // RemoveBannedContentJob.perform_later(user)
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $jobs = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertEquals([new RemoveBannedContentJob($kevin)], array_values(array_filter($jobs, static fn (object $job): bool => $job instanceof RemoveBannedContentJob)));
    }

    public function testPrivateAddressesMakeTheBanUnprocessable(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $kevin = self::id('users.kevin');
        \App\Tests\Support\SeedDatabase::connect($this->storagePath)->insert('sessions', ['user_id' => $kevin, 'token' => 'tok', 'ip_address' => '127.0.0.1', 'last_active_at' => '2026-03-02 16:00:00', 'created_at' => '2026-03-02 16:00:00', 'updated_at' => '2026-03-02 16:00:00']);

        $response = $this->submit($client, 'POST', "/users/{$kevin}/ban");

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (int) $this->value('SELECT status FROM users WHERE id = ?', [$kevin]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM bans WHERE user_id = ?', [$kevin]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$kevin]));
    }

    public function testUnban(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $mallory = self::id('users.mallory');
        $response = $this->submit($client, 'DELETE', "/users/{$mallory}/ban");

        self::assertSame("http://localhost/users/{$mallory}", $response->headers->get('Location'));
        self::assertSame(0, (int) $this->value('SELECT status FROM users WHERE id = ?', [$mallory]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM bans WHERE user_id = ?', [$mallory]));
    }

    public function testOnlyAdministratorsBan(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $response = $this->submit($client, 'POST', '/users/'.self::id('users.jz').'/ban');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(0, (int) $this->value('SELECT status FROM users WHERE id = ?', [self::id('users.jz')]));
    }

    public function testBannedAddressesCannotWrite(): void
    {
        $client = $this->client();
        $response = $this->submit($client, 'POST', '/session', ['email_address' => 'x', 'password' => 'y'], server: ['REMOTE_ADDR' => (string) self::labels('ips.banned')]);

        self::assertSame(429, $response->getStatusCode());
    }

    public function testUnknownUserIsNotFound(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertSame(404, $this->submit($client, 'POST', '/users/me/ban')->getStatusCode());
    }
}
