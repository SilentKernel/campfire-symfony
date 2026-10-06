<?php

declare(strict_types=1);

namespace App\Cable;

use App\Database\Transactions;
use App\Rails\RailsJson;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Publishes broadcasts and remote disconnects to campfire:cable over its unix socket
 * (ControlFrame), the way Rails publishes to Redis for Action Cable.
 *
 * - Inside a transaction, a publication waits for the commit (and is dropped on rollback), like
 *   the `after_*_commit` callbacks Rails broadcasts from.
 * - Publications are buffered and written together: at the end of the request
 *   (BroadcastFlushListener), when a job or command finishes, on kernel reset, or once 64 KB
 *   are pending. flush() sends them right away.
 * - The socket stays open across requests (worker mode) and reconnects lazily. Writes are
 *   non-blocking with a short deadline. When the cable server is down, publications are dropped
 *   with a warning (at most one per second) and the request carries on, as a Redis outage only
 *   loses broadcasts in Rails.
 */
#[AsAlias(Broadcaster::class)]
#[When(env: 'dev')]
#[When(env: 'prod')]
final class SocketBroadcaster implements Broadcaster, ResetInterface
{
    private const int FLUSH_THRESHOLD = 65536;
    private const float CONNECT_TIMEOUT = 0.05;
    private const float WRITE_TIMEOUT = 0.25;
    private const float RETRY_AFTER = 1.0;

    /** @var resource|null */
    private $socket;

    private string $buffer = '';

    private float $downUntil = 0.0;

    public function __construct(
        #[Autowire('%campfire.cable_socket%')] private readonly string $cableSocket,
        private readonly ?Transactions $transactions = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function broadcast(string $stream, string|array $payload): void
    {
        $this->publish(ControlFrame::broadcast($stream, RailsJson::encode($payload)));
    }

    public function disconnectUser(int $userId, bool $reconnect = false): void
    {
        $this->publish(ControlFrame::disconnect($userId, $reconnect));
    }

    /** Writes everything pending now. Never throws. */
    public function flush(): void
    {
        if ('' === $this->buffer) {
            return;
        }
        $data = $this->buffer;
        $this->buffer = '';

        if (microtime(true) < $this->downUntil) {
            return;
        }
        if (!$this->write($data)) {
            // A server restart leaves us a dead socket: reconnect once before giving up.
            $this->disconnect();
            if (!$this->write($data)) {
                $this->disconnect();
                $this->downUntil = microtime(true) + self::RETRY_AFTER;
                $this->logger->warning('Action Cable server unreachable at {socket}; broadcasts dropped', ['socket' => $this->cableSocket]);
            }
        }
    }

    public function hasPending(): bool
    {
        return '' !== $this->buffer;
    }

    public function reset(): void
    {
        $this->flush();
    }

    public function __destruct()
    {
        $this->flush();
        $this->disconnect();
    }

    private function publish(string $frame): void
    {
        if (null !== $this->transactions && $this->transactions->inTransaction()) {
            $this->transactions->afterCommit(fn () => $this->enqueue($frame));
        } else {
            $this->enqueue($frame);
        }
    }

    private function enqueue(string $frame): void
    {
        $this->buffer .= $frame;
        if (\strlen($this->buffer) >= self::FLUSH_THRESHOLD) {
            $this->flush();
        }
    }

    private function write(string $data): bool
    {
        $socket = $this->socket ?? $this->connect();
        if (null === $socket) {
            return false;
        }

        $deadline = microtime(true) + self::WRITE_TIMEOUT;
        $length = \strlen($data);
        $written = 0;
        set_error_handler(static fn (): bool => true);
        try {
            while ($written < $length) {
                $n = fwrite($socket, 0 === $written ? $data : substr($data, $written));
                if (false === $n) {
                    return false;
                }
                $written += $n;
                if ($written >= $length) {
                    break;
                }
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    return false;
                }
                $read = $except = null;
                $write = [$socket];
                $ready = stream_select($read, $write, $except, 0, (int) ($left * 1_000_000));
                if (false === $ready || 0 === $ready) {
                    return false;
                }
            }
        } finally {
            restore_error_handler();
        }

        return true;
    }

    /** @return resource|null */
    private function connect()
    {
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('unix://'.$this->cableSocket, $errno, $error, self::CONNECT_TIMEOUT);
        } finally {
            restore_error_handler();
        }
        if (false === $socket) {
            return null;
        }
        stream_set_blocking($socket, false);

        return $this->socket = $socket;
    }

    private function disconnect(): void
    {
        if (null !== $this->socket) {
            set_error_handler(static fn (): bool => true);
            fclose($this->socket);
            restore_error_handler();
            $this->socket = null;
        }
    }
}
