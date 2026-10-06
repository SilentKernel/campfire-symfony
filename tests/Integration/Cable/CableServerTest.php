<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cable;

use App\Cable\SocketBroadcaster;
use App\Cable\StreamNames;
use App\Rails\KeyGenerator;
use App\Rails\RailsCookies;
use App\Rails\TurboStreamName;
use App\Tests\Support\SeedDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * campfire:cable end to end: a real server process, real sockets, the seed's sessions and rooms,
 * and broadcasts published through SocketBroadcaster as the app publishes them.
 */
#[Group('integration')]
final class CableServerTest extends TestCase
{
    private static CableServerProcess $server;

    private static Connection $db;

    private static string $davidCookie;

    private static string $jasonCookie;

    private static TurboStreamName $streams;

    public static function setUpBeforeClass(): void
    {
        self::$server = new CableServerProcess();
        self::$db = SeedDatabase::connect(self::$server->storagePath);
        $keys = new KeyGenerator((string) $_SERVER['SECRET_KEY_BASE']);
        self::$streams = new TurboStreamName($keys);
        $cookies = new RailsCookies($keys);
        $cookieFor = static function (string $user) use ($cookies): string {
            $token = self::$db->fetchOne('SELECT token FROM sessions WHERE user_id = ? ORDER BY id LIMIT 1', [self::id('users.'.$user)]);
            if (false === $token) {
                $token = 'cable-test-'.$user;
                self::$db->insert('sessions', ['user_id' => self::id('users.'.$user), 'token' => $token, 'last_active_at' => '2026-03-02 16:00:00', 'created_at' => '2026-03-02 16:00:00', 'updated_at' => '2026-03-02 16:00:00']);
            }

            return 'session_token='.RailsCookies::escape($cookies->writeSigned('session_token', (string) $token));
        };
        self::$davidCookie = $cookieFor('david');
        self::$jasonCookie = $cookieFor('jason');
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->close();
        self::$server->stop();
    }

    protected function tearDown(): void
    {
        self::assertTrue(self::$server->isRunning(), self::$server->output());
    }

    public function testPlainHttpGetsPageNotFound(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$server->port, $errno, $error, 2);
        self::assertNotFalse($socket);
        fwrite($socket, "GET /cable HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        self::assertStringStartsWith('HTTP/1.1 404 Not Found', (string) $response);
        self::assertStringContainsString("Content-Type: text/plain; charset=utf-8\r\n", (string) $response);
        self::assertStringEndsWith("\r\n\r\nPage not found", (string) $response);
    }

    public function testCrossOriginUpgradeIsRefused(): void
    {
        $client = new CableClient(self::$server->port, ['Origin' => 'http://evil.example', 'Cookie' => self::$davidCookie]);

        self::assertSame(404, $client->status());
        self::assertSame('Page not found', $client->body());
    }

    public function testSameOriginOverHttpsWhenTheProxySaysSo(): void
    {
        $client = new CableClient(self::$server->port, ['Origin' => 'https://127.0.0.1:'.self::$server->port, 'X-Forwarded-Proto' => 'https', 'Cookie' => self::$davidCookie]);

        self::assertSame(101, $client->status());
        self::assertSame('{"type":"welcome"}', $client->text());
    }

    public function testUnauthenticatedConnectionIsToldNotToReconnect(): void
    {
        $client = new CableClient(self::$server->port);

        self::assertSame(101, $client->status());
        self::assertStringContainsString("\r\nSec-WebSocket-Protocol: actioncable-v1-json", $client->responseHead);
        self::assertSame('{"type":"disconnect","reason":"unauthorized","reconnect":false}', $client->text());
        self::assertSame(['close', 1000], $client->frame());
        self::assertTrue($client->isEnded());
    }

    public function testForgedSessionCookieIsUnauthorized(): void
    {
        $client = new CableClient(self::$server->port, ['Cookie' => 'session_token=forged--0000']);

        self::assertSame('{"type":"disconnect","reason":"unauthorized","reconnect":false}', $client->text());
    }

    public function testSubscriptionsAreConfirmedOrRejectedLikeRails(): void
    {
        $client = $this->connect();
        $watercooler = self::id('rooms.watercooler');
        $notMine = self::id('rooms.bender_and_kevin');

        $heartbeat = $client->subscribe(['channel' => 'HeartbeatChannel']);
        self::assertSame(['identifier' => $heartbeat, 'type' => 'confirm_subscription'], $client->json());

        $client->subscribe(['channel' => 'HeartbeatChannel']);
        self::assertNull($client->json(0.3), 'a repeated identifier is ignored');

        $room = $client->subscribe(['channel' => 'RoomChannel', 'room_id' => $watercooler]);
        self::assertSame(['identifier' => $room, 'type' => 'confirm_subscription'], $client->json());

        $other = $client->subscribe(['channel' => 'RoomChannel', 'room_id' => $notMine]);
        self::assertSame(['identifier' => $other, 'type' => 'reject_subscription'], $client->json());

        $client->subscribe(['channel' => 'NoSuchChannel']);
        self::assertNull($client->json(0.3), 'an unknown channel gets no answer');

        $base = $client->subscribe(['channel' => 'ApplicationCable::Channel']);
        self::assertSame('confirm_subscription', $client->json()['type'] ?? null, $base);

        $rooms = $client->subscribe(['channel' => 'Turbo::StreamsChannel', 'signed_stream_name' => self::$streams->sign('rooms')]);
        self::assertSame(['identifier' => $rooms, 'type' => 'confirm_subscription'], $client->json());

        $forged = $client->subscribe(['channel' => 'Turbo::StreamsChannel', 'signed_stream_name' => 'InJvb21zIg==--0000']);
        self::assertSame(['identifier' => $forged, 'type' => 'reject_subscription'], $client->json());

        $unsigned = $client->subscribe(['channel' => 'Turbo::StreamsChannel']);
        self::assertSame(['identifier' => $unsigned, 'type' => 'reject_subscription'], $client->json());

        $messages = self::$streams->sign(StreamNames::roomGidParam('Rooms::Closed', $watercooler).':messages');
        $guarded = $client->subscribe(['channel' => 'Turbo::StreamsChannel', 'signed_stream_name' => $messages]);
        self::assertSame(['identifier' => $guarded, 'type' => 'reject_subscription'], $client->json(), 'room messages only through RoomMessagesChannel');

        $roomMessages = $client->subscribe(['channel' => 'RoomMessagesChannel', 'signed_stream_name' => $messages]);
        self::assertSame(['identifier' => $roomMessages, 'type' => 'confirm_subscription'], $client->json());

        $wrongType = self::$streams->sign(StreamNames::roomGidParam('Rooms::Open', $watercooler).':messages');
        $client->subscribe(['channel' => 'RoomMessagesChannel', 'signed_stream_name' => $wrongType]);
        self::assertSame('reject_subscription', $client->json()['type'] ?? null, 'an STI class must match the room');

        $direct = self::$streams->sign(StreamNames::roomGidParam('Rooms::Direct', $notMine).':messages');
        $client->subscribe(['channel' => 'RoomMessagesChannel', 'signed_stream_name' => $direct]);
        self::assertSame('reject_subscription', $client->json()['type'] ?? null, 'not a member');

        $client->sendText('not json');
        $client->sendJson(['command' => 'dance']);
        $client->perform($room, ['action' => 'nope']);
        self::assertNull($client->json(0.3), 'malformed commands are ignored');

        $client->subscribe(['channel' => 'HeartbeatChannel', 'x' => 1]);
        self::assertSame('confirm_subscription', $client->json()['type'] ?? null, 'the connection still works');
    }

    public function testBroadcastReachesEachSubscriptionOnce(): void
    {
        $a = $this->connect();
        $b = $this->connect(self::$jasonCookie);
        $rooms = ['channel' => 'Turbo::StreamsChannel', 'signed_stream_name' => self::$streams->sign('rooms')];
        $identifierA = $a->subscribe($rooms);
        $identifierB = $b->subscribe($rooms);
        self::assertSame('confirm_subscription', $a->json()['type'] ?? null);
        self::assertSame('confirm_subscription', $b->json()['type'] ?? null);

        $html = '<turbo-stream action="remove" target="list_rooms_open_1"></turbo-stream> & café';
        $broadcaster = new SocketBroadcaster(self::$server->socketPath);
        $broadcaster->broadcast(StreamNames::rooms(), $html);
        $broadcaster->broadcast('nobody-listens', 'x');
        $broadcaster->flush();

        // Active Support JSON: <, > and & escaped, unicode as is
        $escaped = strtr((string) json_encode($html, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), ['<' => '\u003c', '>' => '\u003e', '&' => '\u0026']);
        $expected = '{"identifier":'.json_encode($identifierA).',"message":'.$escaped.'}';
        self::assertSame($expected, $a->text());
        self::assertSame(['identifier' => $identifierB, 'message' => $html], $b->json());
        self::assertSame([], $a->drain());
        self::assertSame([], $b->drain());
    }

    public function testRoomMessagesBroadcastWithJsonPayloads(): void
    {
        $client = $this->connect();
        $watercooler = self::id('rooms.watercooler');
        $unread = $client->subscribe(['channel' => 'UnreadRoomsChannel']);
        self::assertSame('confirm_subscription', $client->json()['type'] ?? null);

        $broadcaster = new SocketBroadcaster(self::$server->socketPath);
        $broadcaster->broadcast(StreamNames::unreadRooms(self::id('users.jason')), ['roomId' => $watercooler]);
        $broadcaster->broadcast(StreamNames::unreadRooms(self::id('users.david')), ['roomId' => $watercooler]);
        $broadcaster->flush();

        self::assertSame(['identifier' => $unread, 'message' => ['roomId' => $watercooler]], $client->json());
        self::assertSame([], $client->drain(), "jason's unread stream is not david's");
    }

    public function testPresenceCountsConnectionsAndBroadcastsTheReadRoom(): void
    {
        $watercooler = self::id('rooms.watercooler');
        $membership = self::id('memberships.david_watercooler');
        self::$db->executeStatement("UPDATE memberships SET unread_at = '2026-03-02 15:00:00' WHERE id = ?", [$membership]);

        $a = $this->connect();
        $reads = $a->subscribe(['channel' => 'ReadRoomsChannel']);
        self::assertSame('confirm_subscription', $a->json()['type'] ?? null);
        $presence = $a->subscribe(['channel' => 'PresenceChannel', 'room_id' => $watercooler]);
        self::assertSame(['identifier' => $presence, 'type' => 'confirm_subscription'], $a->json());
        self::assertSame(['identifier' => $reads, 'message' => ['room_id' => $watercooler]], $a->json(), 'after the confirmation');

        $row = self::membership($membership);
        self::assertSame(1, (int) $row['connections']);
        self::assertNotNull($row['connected_at']);
        self::assertNull($row['unread_at']);

        $b = $this->connect();
        $presenceB = $b->subscribe(['channel' => 'PresenceChannel', 'room_id' => (string) $watercooler]);
        self::assertSame('confirm_subscription', $b->json()['type'] ?? null);
        self::assertSame(2, (int) self::membership($membership)['connections']);

        $b->perform($presenceB, ['action' => 'refresh']);
        $b->unsubscribe($presenceB);
        self::assertTrue($this->eventually(fn () => 1 === (int) self::membership($membership)['connections']));

        $a->close();
        self::assertTrue($this->eventually(fn () => 0 === (int) self::membership($membership)['connections'] && null === self::membership($membership)['connected_at']));
    }

    public function testALockedDatabaseDelaysTheCommandNotTheServer(): void
    {
        $waiting = $this->connect();
        $other = $this->connect(self::$jasonCookie);

        self::$db->executeStatement('BEGIN IMMEDIATE');
        try {
            $presence = $waiting->subscribe(['channel' => 'PresenceChannel', 'room_id' => self::id('rooms.hq')]);
            self::assertNull($waiting->json(0.4), 'present waits for the write lock');

            $heartbeat = $other->subscribe(['channel' => 'HeartbeatChannel']);
            self::assertSame(['identifier' => $heartbeat, 'type' => 'confirm_subscription'], $other->json(0.5), 'meanwhile the loop serves others');
        } finally {
            self::$db->executeStatement('COMMIT');
        }

        self::assertSame(['identifier' => $presence, 'type' => 'confirm_subscription'], $waiting->json(3.0));
        self::assertSame(1, (int) self::membership(self::id('memberships.david_hq'))['connections']);
    }

    public function testTypingNotificationsReachTheRoom(): void
    {
        $watercooler = self::id('rooms.watercooler');
        $a = $this->connect();
        $b = $this->connect(self::$jasonCookie);
        $typingA = $a->subscribe(['channel' => 'TypingNotificationsChannel', 'room_id' => $watercooler]);
        $typingB = $b->subscribe(['channel' => 'TypingNotificationsChannel', 'room_id' => $watercooler]);
        self::assertSame('confirm_subscription', $a->json()['type'] ?? null);
        self::assertSame('confirm_subscription', $b->json()['type'] ?? null);

        $a->perform($typingA, ['action' => 'start']);
        $user = ['id' => self::id('users.david'), 'name' => 'David'];
        self::assertSame(['identifier' => $typingA, 'message' => ['action' => 'start', 'user' => $user]], $a->json());
        self::assertSame(['identifier' => $typingB, 'message' => ['action' => 'start', 'user' => $user]], $b->json());

        $a->perform($typingA, ['action' => 'stop']);
        self::assertSame('stop', $b->json()['message']['action'] ?? null);
    }

    public function testRemoteDisconnectClosesEveryConnectionOfTheUser(): void
    {
        $david = $this->connect();
        $david2 = $this->connect();
        $jason = $this->connect(self::$jasonCookie);
        $david->subscribe(['channel' => 'HeartbeatChannel']);
        self::assertSame('confirm_subscription', $david->json()['type'] ?? null);

        $broadcaster = new SocketBroadcaster(self::$server->socketPath);
        $broadcaster->disconnectUser(self::id('users.david'), reconnect: true);
        $broadcaster->flush();

        foreach ([$david, $david2] as $client) {
            self::assertSame('{"type":"disconnect","reason":"remote","reconnect":true}', $client->text());
            self::assertSame(['close', 1000], $client->frame());
            self::assertTrue($client->isEnded());
        }
        self::assertNull($jason->json(0.3));
        self::assertFalse($jason->isEnded(0.1));
    }

    public function testPingsEveryThreeSeconds(): void
    {
        $client = $this->connect();
        $frame = $client->frame(4.0, pings: true);

        self::assertNotNull($frame);
        self::assertMatchesRegularExpression('/\A\{"type":"ping","message":\d{10}\}\z/', (string) $frame[1]);
    }

    public function testClientCloseIsAnswered(): void
    {
        $client = $this->connect();
        $client->sendText(pack('n', 1000), 0x8);

        self::assertSame(['close', 1000], $client->frame());
        self::assertTrue($client->isEnded());
    }

    public function testTwoHundredClientsReceiveEveryBroadcast(): void
    {
        $identifier = ['channel' => 'Turbo::StreamsChannel', 'signed_stream_name' => self::$streams->sign('rooms')];
        $clients = [];
        for ($i = 0; $i < 200; ++$i) {
            $client = $this->connect($i % 2 ? self::$jasonCookie : self::$davidCookie);
            $client->subscribe($identifier);
            $clients[] = $client;
        }
        foreach ($clients as $client) {
            self::assertSame('confirm_subscription', $client->json(5.0)['type'] ?? null);
        }

        $broadcaster = new SocketBroadcaster(self::$server->socketPath);
        for ($n = 1; $n <= 25; ++$n) {
            $broadcaster->broadcast(StreamNames::rooms(), "<turbo-stream>bmk{$n}z ".str_repeat('x', 2000).'</turbo-stream>');
            if (0 === $n % 5) {
                $broadcaster->flush();
            }
        }
        $broadcaster->flush();

        foreach ($clients as $i => $client) {
            for ($n = 1; $n <= 25; ++$n) {
                $message = $client->json(5.0)['message'] ?? null;
                self::assertIsString($message, "client $i, message $n");
                self::assertStringStartsWith("<turbo-stream>bmk{$n}z ", $message);
            }
        }
        foreach ($clients as $client) {
            self::assertSame([], $client->drain(0.05));
            $client->close();
        }
    }

    public function testThirdPartyClient(): void
    {
        $client = new Client(self::$server->url());
        $client->addHeader('Origin', self::$server->origin())
            ->addHeader('Cookie', self::$davidCookie)
            ->addHeader('Sec-WebSocket-Protocol', 'actioncable-v1-json')
            ->setTimeout(3);
        $client->connect();

        $welcome = $client->receive();
        self::assertInstanceOf(Text::class, $welcome);
        self::assertSame('{"type":"welcome"}', $welcome->getContent());

        $client->text((string) json_encode(['command' => 'subscribe', 'identifier' => '{"channel":"HeartbeatChannel"}']));
        self::assertSame('{"identifier":"{\"channel\":\"HeartbeatChannel\"}","type":"confirm_subscription"}', $client->receive()->getContent());
        $client->disconnect();
    }

    public function testBroadcasterSurvivesAServerThatIsNotThere(): void
    {
        $broadcaster = new SocketBroadcaster(self::$server->storagePath.'/missing.sock');
        $broadcaster->broadcast('rooms', 'x');
        $broadcaster->flush();
        $broadcaster->broadcast('rooms', 'y');
        $broadcaster->flush();

        self::assertFalse($broadcaster->hasPending());
    }

    private function connect(?string $cookie = null): CableClient
    {
        $client = new CableClient(self::$server->port, ['Cookie' => $cookie ?? self::$davidCookie]);
        self::assertSame(101, $client->status(), $client->responseHead);
        self::assertSame('{"type":"welcome"}', $client->text(5.0));

        return $client;
    }

    /** @return array<string, mixed> */
    private static function membership(int $id): array
    {
        $row = self::$db->fetchAssociative('SELECT connections, connected_at, unread_at FROM memberships WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function eventually(callable $condition, float $timeout = 3.0): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if ($condition()) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function id(string $label): int
    {
        return (int) SeedDatabase::labels()[$label];
    }
}
