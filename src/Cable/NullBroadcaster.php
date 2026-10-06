<?php

declare(strict_types=1);

namespace App\Cable;

/** Drops everything (for tools and tests that need a Broadcaster but no cable server). */
final class NullBroadcaster implements Broadcaster
{
    public function broadcast(string $stream, string|array $payload): void
    {
    }

    public function disconnectUser(int $userId, bool $reconnect = false): void
    {
    }
}
