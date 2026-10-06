<?php

declare(strict_types=1);

namespace App\Cable\Server;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * One cable socket: ActionCable::Connection::Base, Connection::Subscriptions and the server side of
 * the WebSocket.
 *
 * Commands run one at a time, in arrival order. A command that finds the database locked runs
 * again a little later (the event loop never waits on SQLite), and the commands behind it wait
 * their turn. Frames produced while a command runs go out in one write when it is done; the
 * broadcasts it made follow (CableServer::hold()).
 */
final class Connection
{
    public const int STATE_HANDSHAKE = 0;
    public const int STATE_AUTHENTICATING = 1;
    public const int STATE_OPEN = 2;
    public const int STATE_CLOSED = 3;

    /** The largest message a client may send; Action Cable commands are a few hundred bytes. */
    public const int MAX_MESSAGE = 1 << 20;
    /** Bounds on what one socket can make the server hold (Rails has none). */
    public const int MAX_SUBSCRIPTIONS = 64;
    public const int MAX_IDENTIFIER_BYTES = 4096;
    public const int MAX_PENDING_COMMANDS = 256;
    private const int MAX_ATTEMPTS = 60;

    public int $state = self::STATE_HANDSHAKE;

    private string $buffer = '';

    private ?string $fragments = null;

    private int $fragmentOpcode = 0;

    /** @var list<string> */
    private array $commands = [];

    private bool $waiting = false;

    private int $attempts = 0;

    private ?CableUser $user = null;

    /** @var array<string, Subscription> keyed by the raw identifier, in subscription order */
    private array $subscriptions = [];

    private string $outbox = '';

    private bool $holding = false;

    public function __construct(public readonly CableServer $server, public readonly Transport $transport, public readonly int $id)
    {
    }

    public function user(): CableUser
    {
        return $this->user ?? throw new \LogicException('The connection is not authenticated');
    }

    public function userId(): ?int
    {
        return $this->user?->id;
    }

    public function isOpen(): bool
    {
        return self::STATE_OPEN === $this->state;
    }

    /** @return array<string, Subscription> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    public function onData(string $data): void
    {
        if (self::STATE_CLOSED === $this->state) {
            return;
        }
        $this->buffer .= $data;
        if (self::STATE_HANDSHAKE === $this->state) {
            $this->readHandshake();
            if (self::STATE_HANDSHAKE === $this->state || self::STATE_CLOSED === $this->state) {
                return;
            }
        }
        $this->readFrames();
    }

    /** The socket is gone: Connection::Base#on_close, which unsubscribes from everything. */
    public function onClose(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->commands = [];
        $this->outbox = '';
        $this->server->forget($this);
        foreach ($this->subscriptions as $subscription) {
            $this->server->runCallback(fn () => $subscription->channel->unsubscribed($subscription), 'unsubscribed');
            $subscription->markUnsubscribed();
            $subscription->stopAllStreams();
        }
        $this->subscriptions = [];
    }

    /** `ApplicationCable::Connection#connect` succeeded: welcome, then the commands that waited. */
    public function opened(CableUser $user): void
    {
        if (self::STATE_AUTHENTICATING !== $this->state) {
            return;
        }
        $this->user = $user;
        $this->state = self::STATE_OPEN;
        $this->server->register($this);
        $this->write(WebSocket::text(Protocol::welcome()));
        $this->processCommands();
    }

    /** `reject_unauthorized_connection`. */
    public function unauthorized(): void
    {
        $this->server->logger->error('An unauthorized connection attempt was rejected');
        $this->close(Protocol::REASON_UNAUTHORIZED, false);
    }

    /** Connection::Base#close: a disconnect message, then the WebSocket close. */
    public function close(?string $reason, bool $reconnect): void
    {
        if (self::STATE_CLOSED === $this->state) {
            return;
        }
        $this->flush();
        $this->state = self::STATE_CLOSED;
        $this->transport->close(WebSocket::text(Protocol::disconnect($reason, $reconnect)).WebSocket::close());
    }

    /** A frame for this socket: written now, or with the rest of the current command's frames. */
    public function write(string $frame): void
    {
        if (self::STATE_CLOSED === $this->state) {
            return;
        }
        if ($this->holding) {
            $this->outbox .= $frame;
        } elseif ($this->server->isBatching()) {
            // Several broadcasts read together go out in one write per socket (CableServer::batch()).
            if ('' === $this->outbox) {
                $this->server->pending($this);
            }
            $this->outbox .= $frame;
        } else {
            $this->transport->send($frame);
        }
    }

    public function queue(string $frame): void
    {
        $this->write($frame);
    }

    public function flush(): void
    {
        if ('' !== $this->outbox) {
            $outbox = $this->outbox;
            $this->outbox = '';
            $this->transport->send($outbox);
        }
    }

    private function readHandshake(): void
    {
        $end = strpos($this->buffer, "\r\n\r\n");
        if (false === $end) {
            if (\strlen($this->buffer) > Handshake::MAX_HEADER_BYTES) {
                $this->reject(Handshake::badRequest());
            }

            return;
        }
        $handshake = Handshake::parse(substr($this->buffer, 0, $end + 4));
        $this->buffer = (string) substr($this->buffer, $end + 4);
        if (null === $handshake) {
            $this->reject(Handshake::badRequest());

            return;
        }
        if (!$handshake->isWebSocket()) {
            $this->server->logger->error(\sprintf('Failed to upgrade to WebSocket (REQUEST_METHOD: %s, HTTP_CONNECTION: %s, HTTP_UPGRADE: %s)', $handshake->method, $handshake->header('connection') ?? '', $handshake->header('upgrade') ?? ''));
            $this->reject(Handshake::pageNotFound());

            return;
        }
        if (!$this->server->allowsOrigin($handshake)) {
            $this->server->logger->error(\sprintf('Request origin not allowed: %s', $handshake->header('origin') ?? ''));
            $this->reject(Handshake::pageNotFound());

            return;
        }
        $response = $handshake->accept();
        if (null === $response) {
            $this->reject(Handshake::badRequest());

            return;
        }
        $this->transport->send($response);
        $this->state = self::STATE_AUTHENTICATING;
        $this->server->authenticate($this, $handshake);
    }

    private function reject(string $httpResponse): void
    {
        $this->state = self::STATE_CLOSED;
        $this->transport->close($httpResponse);
    }

    private function readFrames(): void
    {
        while ('' !== $this->buffer && self::STATE_CLOSED !== $this->state) {
            $frame = WebSocket::parse($this->buffer, self::MAX_MESSAGE);
            if (null === $frame) {
                return;
            }
            if (\is_int($frame)) {
                $this->protocolError($frame);

                return;
            }
            $this->buffer = (string) substr($this->buffer, $frame['length']);

            switch ($frame['opcode']) {
                case WebSocket::OP_TEXT:
                case WebSocket::OP_BINARY:
                    if (null !== $this->fragments) {
                        $this->protocolError(WebSocket::CLOSE_PROTOCOL_ERROR);

                        return;
                    }
                    if ($frame['fin']) {
                        $this->message($frame['opcode'], $frame['payload']);
                    } else {
                        $this->fragments = $frame['payload'];
                        $this->fragmentOpcode = $frame['opcode'];
                    }
                    break;
                case WebSocket::OP_CONTINUATION:
                    if (null === $this->fragments) {
                        $this->protocolError(WebSocket::CLOSE_PROTOCOL_ERROR);

                        return;
                    }
                    $this->fragments .= $frame['payload'];
                    if (\strlen($this->fragments) > self::MAX_MESSAGE) {
                        $this->protocolError(WebSocket::CLOSE_TOO_LARGE);

                        return;
                    }
                    if ($frame['fin']) {
                        $message = $this->fragments;
                        $this->fragments = null;
                        $this->message($this->fragmentOpcode, $message);
                    }
                    break;
                case WebSocket::OP_PING:
                    $this->transport->send(WebSocket::pong($frame['payload']));
                    break;
                case WebSocket::OP_PONG:
                    break;
                case WebSocket::OP_CLOSE:
                    // Complete the closing handshake (RFC 6455 5.5.1), echoing the status code.
                    $this->state = self::STATE_CLOSED;
                    $code = \strlen($frame['payload']) >= 2 ? substr($frame['payload'], 0, 2) : '';
                    $this->transport->close(WebSocket::frame(WebSocket::OP_CLOSE, $code));

                    return;
                default:
                    $this->protocolError(WebSocket::CLOSE_PROTOCOL_ERROR);

                    return;
            }
        }
    }

    private function protocolError(int $code): void
    {
        $this->state = self::STATE_CLOSED;
        $this->transport->close(WebSocket::close($code));
    }

    private function message(int $opcode, string $payload): void
    {
        if (WebSocket::OP_TEXT !== $opcode) {
            $this->server->logger->error("Couldn't handle non-string message: Array");

            return;
        }
        if (!mb_check_encoding($payload, 'UTF-8')) {
            $this->protocolError(1007);

            return;
        }
        if (\count($this->commands) >= self::MAX_PENDING_COMMANDS) {
            $this->server->logger->error('Too many pending commands; closing the connection');
            $this->close(null, true);

            return;
        }
        $this->commands[] = $payload;
        $this->processCommands();
    }

    private function processCommands(): void
    {
        if (self::STATE_OPEN !== $this->state || $this->waiting || [] === $this->commands) {
            return;
        }
        $this->server->hold();
        $this->holding = true;
        try {
            while ([] !== $this->commands && self::STATE_OPEN === $this->state) {
                try {
                    $this->execute($this->commands[0]);
                } catch (LockWaitTimeoutException $locked) {
                    if (++$this->attempts < self::MAX_ATTEMPTS) {
                        $this->waiting = true;
                        $this->server->delay(min(0.005 * 2 ** $this->attempts, 0.25), function (): void {
                            $this->waiting = false;
                            $this->processCommands();
                        });
                        break;
                    }
                    $this->server->logger->error('Could not execute command: the database stayed locked', ['exception' => $locked]);
                }
                $this->attempts = 0;
                array_shift($this->commands);
            }
        } finally {
            $this->holding = false;
            $this->flush();
            $this->server->release();
        }
    }

    /** Subscriptions#execute_command: anything malformed is logged and ignored. */
    private function execute(string $text): void
    {
        try {
            $data = json_decode($text, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
            if (!\is_array($data)) {
                throw new \UnexpectedValueException('not a hash');
            }
            match ($data['command'] ?? null) {
                'subscribe' => $this->add($data),
                'unsubscribe' => $this->remove($data),
                'message' => $this->performAction($data),
                default => $this->server->logger->error('Received unrecognized command', ['data' => $text]),
            };
        } catch (LockWaitTimeoutException $locked) {
            throw $locked;
        } catch (\Throwable $error) {
            $this->server->logger->error(\sprintf('Could not execute command from (%s) [%s - %s]', $text, $error::class, $error->getMessage()));
        }
    }

    /** @param array<array-key, mixed> $data */
    private function add(array $data): void
    {
        $identifier = $data['identifier'] ?? null;
        if (!\is_string($identifier) || !\is_object(json_decode($identifier, false, 64))) {
            throw new \UnexpectedValueException('invalid identifier');
        }
        if (isset($this->subscriptions[$identifier])) {
            return;
        }
        if (\count($this->subscriptions) >= self::MAX_SUBSCRIPTIONS || \strlen($identifier) > self::MAX_IDENTIFIER_BYTES) {
            $this->server->logger->error('Subscription limit reached');

            return;
        }
        /** @var array<string, mixed> $params */
        $params = (array) json_decode($identifier, true, 64, \JSON_BIGINT_AS_STRING);
        $requested = $params['channel'] ?? null;
        $created = \is_string($requested) ? $this->server->channels->create($requested) : null;
        if (null === $created) {
            $this->server->logger->error('Subscription class not found: '.json_encode($requested));

            return;
        }
        [$className, $channel] = $created;
        $subscription = new Subscription($this, $identifier, $params, $className, $channel);
        $this->subscriptions[$identifier] = $subscription;

        // Channel::Base#subscribe_to_channel
        try {
            $channel->subscribed($subscription);
        } catch (LockWaitTimeoutException $locked) {
            $subscription->stopAllStreams();
            unset($this->subscriptions[$identifier]);
            throw $locked;
        } catch (\Throwable $error) {
            // Rails leaves the subscription registered, neither confirmed nor rejected.
            $this->server->logger->error(\sprintf('Could not execute command [%s - %s]', $error::class, $error->getMessage()));

            return;
        }

        if ($subscription->isRejected()) {
            $this->removeSubscription($subscription);
            $this->write(WebSocket::text(Protocol::rejection($subscription->encodedIdentifier)));
        } else {
            $this->write(WebSocket::text(Protocol::confirmation($subscription->encodedIdentifier)));
        }
    }

    /** @param array<array-key, mixed> $data */
    private function remove(array $data): void
    {
        $subscription = $this->find($data);
        if (null !== $subscription) {
            $this->removeSubscription($subscription);
        }
    }

    private function removeSubscription(Subscription $subscription): void
    {
        try {
            $subscription->channel->unsubscribed($subscription);
        } catch (LockWaitTimeoutException $locked) {
            throw $locked;
        } catch (\Throwable $error) {
            $this->server->logger->error(\sprintf('Could not execute command [%s - %s]', $error::class, $error->getMessage()));
        }
        $subscription->markUnsubscribed();
        $subscription->stopAllStreams();
        unset($this->subscriptions[$subscription->identifier]);
    }

    /** @param array<array-key, mixed> $data */
    private function performAction(array $data): void
    {
        $subscription = $this->find($data);
        if (null === $subscription) {
            return;
        }
        $payload = \is_string($data['data'] ?? null) ? json_decode($data['data'], true, 64, \JSON_BIGINT_AS_STRING) : null;
        if (!\is_array($payload)) {
            throw new \UnexpectedValueException('invalid data');
        }
        // (data["action"].presence || :receive)
        $action = $payload['action'] ?? null;
        if (null === $action || (\is_string($action) && '' === trim($action))) {
            $action = 'receive';
        } elseif (!\is_string($action)) {
            throw new \UnexpectedValueException('invalid action');
        }

        if ($subscription->isRejected() || !$subscription->channel->perform($action, $payload, $subscription)) {
            $this->server->logger->error(\sprintf('Unable to process %s#%s', $subscription->className, $action));
        }
    }

    /** @param array<array-key, mixed> $data */
    private function find(array $data): ?Subscription
    {
        $identifier = $data['identifier'] ?? null;
        $subscription = \is_string($identifier) ? ($this->subscriptions[$identifier] ?? null) : null;
        if (null === $subscription) {
            $this->server->logger->error('Unable to find subscription with identifier: '.(\is_string($identifier) ? $identifier : ''));
        }

        return $subscription;
    }
}
