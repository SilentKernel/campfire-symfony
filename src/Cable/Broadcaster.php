<?php

declare(strict_types=1);

namespace App\Cable;

/**
 * Publishes to the Action Cable server (`ActionCable.server.broadcast` and
 * `remote_connections.where(current_user:).disconnect`). Call it from an after-commit callback.
 */
interface Broadcaster
{
    /** @param string|array<string, mixed> $payload a rendered Turbo Stream, or a message to JSON-encode */
    public function broadcast(string $stream, string|array $payload): void;

    /** Closes the user's connections; with $reconnect the clients are told to reconnect. */
    public function disconnectUser(int $userId, bool $reconnect = false): void;
}
