<?php

declare(strict_types=1);

namespace App\Cable\Server;

use App\Cable\Channel\Channel;
use App\Cable\StreamNames;
use App\Rails\RailsJson;

/**
 * The per-subscription state of an ActionCable::Channel::Base: identifier, params, streams,
 * rejection, and what it transmits.
 */
final class Subscription
{
    /** The identifier as a JSON string, ready to go into every frame. */
    public readonly string $encodedIdentifier;

    /** @var array<string, true> */
    private array $streams = [];

    private bool $rejected = false;

    private bool $unsubscribed = false;

    /**
     * @param string               $identifier the raw identifier string the client subscribed with
     * @param array<string, mixed> $params     the decoded identifier, `channel` included
     * @param string               $className  the channel class as registered ("RoomChannel")
     */
    public function __construct(
        public readonly Connection $connection,
        public readonly string $identifier,
        public readonly array $params,
        public readonly string $className,
        public readonly Channel $channel,
    ) {
        $this->encodedIdentifier = RailsJson::encode($identifier);
    }

    public function currentUser(): CableUser
    {
        return $this->connection->user();
    }

    public function server(): CableServer
    {
        return $this->connection->server;
    }

    public function param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function streamFrom(string $broadcasting): void
    {
        if ($this->unsubscribed || isset($this->streams[$broadcasting])) {
            return;
        }
        $this->streams[$broadcasting] = true;
        $this->server()->addStream($broadcasting, $this);
    }

    /** `stream_for`: broadcasting_for(this channel, parts). */
    public function streamFor(string ...$parts): void
    {
        $this->streamFrom(StreamNames::broadcastingFor($this->className, ...$parts));
    }

    /**
     * `broadcast_to` for this channel's class.
     *
     * @param array<string, mixed> $message
     */
    public function broadcastTo(array $message, string ...$parts): void
    {
        $this->server()->broadcast(StreamNames::broadcastingFor($this->className, ...$parts), RailsJson::encode($message));
    }

    public function stopAllStreams(): void
    {
        foreach (array_keys($this->streams) as $broadcasting) {
            $this->server()->removeStream((string) $broadcasting, $this);
        }
        $this->streams = [];
    }

    /** @return list<string> */
    public function streams(): array
    {
        return array_map(strval(...), array_keys($this->streams));
    }

    public function reject(): void
    {
        $this->rejected = true;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function markUnsubscribed(): void
    {
        $this->unsubscribed = true;
    }

    /** `transmit`: `{"identifier":…,"message":…}` to this subscriber only. */
    public function transmit(mixed $message): void
    {
        $this->connection->queue(WebSocket::text(Protocol::message($this->encodedIdentifier, RailsJson::encode($message))));
    }
}
