<?php

declare(strict_types=1);

namespace App\Cable\Server;

use App\Cable\ControlFrame;
use App\Rails\RailsCookies;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * ActionCable::Server::Base for one process: the connections, the stream index (pubsub), the
 * heartbeat and remote disconnects.
 *
 * A broadcast is encoded once per subscription identifier (every subscriber of a Turbo stream
 * shares one), framed once, and the same bytes are written to each socket. Streams map to their
 * subscriptions directly, so a broadcast costs nothing for the connections that do not follow it.
 */
final class CableServer
{
    private const int MAX_AUTH_ATTEMPTS = 60;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, array<int, Connection>> open connections by user id */
    private array $users = [];

    /** @var array<string, array<string, array<int, Subscription>>> stream => encoded identifier => subscriptions */
    private array $streams = [];

    private int $holds = 0;

    /** @var list<array{0: string, 1: string}> */
    private array $deferred = [];

    private int $nextId = 1;

    private bool $batching = false;

    /** @var array<int, Connection> connections with frames waiting for the end of the batch */
    private array $pending = [];

    /** @var \Closure(float, \Closure(): void): void */
    private \Closure $delay;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public readonly ChannelRegistry $channels,
        private readonly CableRepository $repository,
        private readonly RailsCookies $cookies,
        private readonly ClockInterface $clock,
        public readonly LoggerInterface $logger,
        private readonly bool $assumeSsl = true,
        private readonly array $allowedOrigins = [],
        private readonly bool $disableForgeryProtection = false,
    ) {
        $this->delay = static function (float $seconds, \Closure $callback): void {
            \Workerman\Timer::delay($seconds, $callback);
        };
    }

    /** @param \Closure(float, \Closure(): void): void $delay */
    public function setDelay(\Closure $delay): void
    {
        $this->delay = $delay;
    }

    public function delay(float $seconds, \Closure $callback): void
    {
        ($this->delay)($seconds, function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $error) {
                $this->logger->error('Cable timer failed: '.$error->getMessage(), ['exception' => $error]);
            }
        });
    }

    public function open(Transport $transport): Connection
    {
        $connection = new Connection($this, $transport, $this->nextId++);
        $this->connections[$connection->id] = $connection;

        return $connection;
    }

    /** @return array<int, Connection> */
    public function connections(): array
    {
        return $this->connections;
    }

    public function allowsOrigin(Handshake $handshake): bool
    {
        return $handshake->allowsOrigin($this->assumeSsl, $this->allowedOrigins, $this->disableForgeryProtection);
    }

    /**
     * ApplicationCable::Connection#connect: the user of the session named by the signed
     * `session_token` cookie, or reject_unauthorized_connection.
     */
    public function authenticate(Connection $connection, Handshake $handshake, int $attempt = 0): void
    {
        try {
            $user = $this->findVerifiedUser($handshake);
        } catch (LockWaitTimeoutException $locked) {
            if ($attempt < self::MAX_AUTH_ATTEMPTS) {
                $this->delay(min(0.005 * 2 ** ($attempt + 1), 0.25), fn () => $this->authenticate($connection, $handshake, $attempt + 1));

                return;
            }
            $this->logger->error('Could not authenticate a cable connection: the database stayed locked', ['exception' => $locked]);
            $user = null;
        } catch (\Throwable $error) {
            $this->logger->error('Could not authenticate a cable connection: '.$error->getMessage(), ['exception' => $error]);
            $user = null;
        }

        if (Connection::STATE_AUTHENTICATING !== $connection->state) {
            return; // the client went away meanwhile
        }
        null === $user ? $connection->unauthorized() : $connection->opened($user);
    }

    public function register(Connection $connection): void
    {
        $this->users[$connection->user()->id][$connection->id] = $connection;
    }

    public function forget(Connection $connection): void
    {
        unset($this->connections[$connection->id]);
        $userId = $connection->userId();
        if (null !== $userId) {
            unset($this->users[$userId][$connection->id]);
            if ([] === ($this->users[$userId] ?? [])) {
                unset($this->users[$userId]);
            }
        }
    }

    public function addStream(string $stream, Subscription $subscription): void
    {
        $this->streams[$stream][$subscription->encodedIdentifier][spl_object_id($subscription)] = $subscription;
    }

    public function removeStream(string $stream, Subscription $subscription): void
    {
        $key = $subscription->encodedIdentifier;
        unset($this->streams[$stream][$key][spl_object_id($subscription)]);
        if ([] === ($this->streams[$stream][$key] ?? [])) {
            unset($this->streams[$stream][$key]);
            if ([] === ($this->streams[$stream] ?? [])) {
                unset($this->streams[$stream]);
            }
        }
    }

    public function subscriberCount(string $stream): int
    {
        return array_sum(array_map(\count(...), $this->streams[$stream] ?? []));
    }

    /**
     * ActionCable.server.broadcast(stream, message), with the message already encoded as Active
     * Support JSON. While a command runs (hold()), broadcasts wait until its own frames are out.
     */
    public function broadcast(string $stream, string $encodedPayload): void
    {
        if ($this->holds > 0) {
            $this->deferred[] = [$stream, $encodedPayload];

            return;
        }
        $this->deliver($stream, $encodedPayload);
    }

    public function hold(): void
    {
        ++$this->holds;
    }

    public function release(): void
    {
        if (--$this->holds > 0) {
            return;
        }
        $this->holds = 0;
        while ([] !== $this->deferred) {
            [$stream, $payload] = array_shift($this->deferred);
            $this->deliver($stream, $payload);
        }
    }

    /**
     * `remote_connections.where(current_user:).disconnect(reconnect:)`: every connection of the
     * user is sent `{"type":"disconnect","reason":"remote","reconnect":…}` and closed, which
     * unsubscribes its channels.
     */
    public function disconnectUser(int $userId, bool $reconnect): int
    {
        $connections = $this->users[$userId] ?? [];
        foreach ($connections as $connection) {
            $this->logger->info(\sprintf('Removing connection (user %d)', $userId));
            $connection->close(Protocol::REASON_REMOTE, $reconnect);
        }

        return \count($connections);
    }

    /** Connection::Base#beat for every open connection, one frame for all. */
    public function heartbeat(): void
    {
        $frame = WebSocket::text(Protocol::ping(time()));
        foreach ($this->connections as $connection) {
            if ($connection->isOpen()) {
                $connection->write($frame);
            }
        }
    }

    /** `ActionCable.server.restart`: every connection is closed with server_restart. */
    public function restart(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->isOpen()) {
                $connection->close(Protocol::REASON_SERVER_RESTART, true);
            }
        }
    }

    /**
     * Runs $work (the publications read from the publish socket in one go) and then writes, per
     * socket, everything it produced in a single write.
     *
     * @param \Closure(): void $work
     */
    public function batch(\Closure $work): void
    {
        if ($this->batching) {
            $work();

            return;
        }
        $this->batching = true;
        try {
            $work();
        } finally {
            $this->batching = false;
            $pending = $this->pending;
            $this->pending = [];
            foreach ($pending as $connection) {
                $connection->flush();
            }
        }
    }

    public function isBatching(): bool
    {
        return $this->batching;
    }

    public function pending(Connection $connection): void
    {
        $this->pending[$connection->id] = $connection;
    }

    /** Applies one frame from the publish socket (ControlFrame). */
    public function control(string $body): void
    {
        $frame = ControlFrame::decode($body);
        if (null === $frame) {
            $this->logger->error('Invalid control frame');
        } elseif (ControlFrame::BROADCAST === $frame['type']) {
            $this->broadcast($frame['stream'], $frame['payload']);
        } else {
            $this->disconnectUser($frame['userId'], $frame['reconnect']);
        }
    }

    /**
     * Runs a channel callback outside any command (a socket that closed), retrying while the
     * database is locked.
     *
     * @param \Closure(): void $callback
     */
    public function runCallback(\Closure $callback, string $what, int $attempt = 0): void
    {
        try {
            $callback();
        } catch (LockWaitTimeoutException $locked) {
            if ($attempt < self::MAX_AUTH_ATTEMPTS) {
                $this->delay(min(0.005 * 2 ** ($attempt + 1), 0.25), fn () => $this->runCallback($callback, $what, $attempt + 1));
            } else {
                $this->logger->error(\sprintf('Could not run %s: the database stayed locked', $what), ['exception' => $locked]);
            }
        } catch (\Throwable $error) {
            $this->logger->error(\sprintf('Could not run %s [%s - %s]', $what, $error::class, $error->getMessage()));
        }
    }

    private function deliver(string $stream, string $encodedPayload): void
    {
        foreach ($this->streams[$stream] ?? [] as $encodedIdentifier => $subscriptions) {
            $frame = WebSocket::text(Protocol::message((string) $encodedIdentifier, $encodedPayload));
            foreach ($subscriptions as $subscription) {
                $subscription->connection->write($frame);
            }
        }
    }

    private function findVerifiedUser(Handshake $handshake): ?CableUser
    {
        $cookies = RailsCookies::parseCookieHeader($handshake->header('cookie'));
        $token = $this->cookies->readSigned('session_token', $cookies['session_token'] ?? null, $this->clock->now());
        if (!\is_string($token) || '' === $token) {
            return null;
        }
        $user = $this->repository->findUserBySessionToken($token);

        return null === $user ? null : new CableUser($user['id'], $user['name']);
    }
}
