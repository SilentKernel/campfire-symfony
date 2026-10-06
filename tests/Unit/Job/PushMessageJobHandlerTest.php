<?php

declare(strict_types=1);

namespace App\Tests\Unit\Job;

use App\Job\MessageRecords;
use App\Job\PushMessageJob;
use App\Job\PushMessageJobHandler;
use App\Opengraph\HostResolver;
use App\Opengraph\PrivateNetworkGuard;
use App\Push\WebPushPool;
use App\Tests\Support\CampfireTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Room::MessagePusher over the seed, with the push services mocked. */
final class PushMessageJobHandlerTest extends CampfireTestCase
{
    /** @var list<string> */
    private array $endpoints = [];

    public function testPushesToEverythingThenToMentionedMembers(): void
    {
        $keys = self::keys();
        foreach (['jason_chrome', 'jz_chrome', 'kevin_chrome', 'david_chrome'] as $label) {
            $this->connection()->update('push_subscriptions', ['p256dh_key' => $keys[0], 'auth_key' => $keys[1]], ['id' => self::id('push_subscriptions.'.$label)]);
        }

        // messages.mention: David in Designers, mentioning Kevin (involved in mentions, disconnected)
        $this->handler()(new PushMessageJob(self::id('messages.mention')));

        self::assertSame([
            'https://fcm.googleapis.com/fcm/send/456', // JZ, everything
            'https://fcm.googleapis.com/fcm/send/567', // Jason, everything
            'https://fcm.googleapis.com/fcm/send/789', // Kevin, mentioned
        ], $this->endpoints, 'not David (the creator), nor members involved in mentions who are not mentioned');
    }

    public function testPayloads(): void
    {
        $handler = $this->handler();
        $records = static::getContainer()->get(MessageRecords::class);
        \assert($records instanceof MessageRecords);

        $shared = $records->find(self::id('messages.mention'));
        self::assertNotNull($shared);
        self::assertSame(
            ['title' => 'Designers', 'body' => 'David: Hey @Kevin, can you check the table above?', 'path' => '/rooms/'.self::id('rooms.designers')],
            $handler->payload($shared),
        );

        $direct = $records->find(self::id('messages.direct_first'));
        self::assertNotNull($direct);
        self::assertSame('Jason', $handler->payload($direct)['title'], 'a direct message is titled with its author');
        self::assertStringStartsWith('/rooms/'.self::id('rooms.david_and_jason'), $handler->payload($direct)['path']);
    }

    public function testConnectedOrInvisibleMembersAreSkipped(): void
    {
        $handler = $this->handler();
        $records = static::getContainer()->get(MessageRecords::class);
        \assert($records instanceof MessageRecords);
        $message = $records->find(self::id('messages.mention'));
        self::assertNotNull($message);

        $this->connection()->update('memberships', ['connected_at' => '2999-01-01 00:00:00'], ['id' => self::id('memberships.jz_designers')]);
        $this->connection()->update('memberships', ['involvement' => 'invisible'], ['id' => self::id('memberships.jason_designers')]);

        self::assertSame([], $handler->subscriptions($message, 'everything'));
        self::assertSame([self::id('push_subscriptions.kevin_chrome')], array_column($handler->subscriptions($message, 'mentions', [self::id('users.kevin')]), 'id'));
    }

    private function handler(): PushMessageJobHandler
    {
        $container = static::getContainer();
        $records = $container->get(MessageRecords::class);
        $clock = $container->get(ClockInterface::class);
        \assert($records instanceof MessageRecords && $clock instanceof ClockInterface);
        $resolver = new class implements HostResolver {
            public function resolve(string $hostname): array
            {
                return ['142.250.1.1'];
            }
        };
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->endpoints[] = $url;

            return new MockResponse('', ['http_code' => 201]);
        });
        $pool = new WebPushPool($this->connection(), new PrivateNetworkGuard($resolver), $http, (string) $_SERVER['VAPID_PUBLIC_KEY'], (string) $_SERVER['VAPID_PRIVATE_KEY']);

        return new PushMessageJobHandler($this->connection(), $records, $pool, $clock);
    }

    /** @return array{string, string} */
    private static function keys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);
        $ec = openssl_pkey_get_details($key)['ec'] ?? [];
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [$encode("\x04".str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT)), $encode(random_bytes(16))];
    }
}
