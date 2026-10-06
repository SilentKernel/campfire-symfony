<?php

declare(strict_types=1);

namespace App\Cable;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The test environment's Broadcaster: records broadcasts and disconnects for assertions. Its
 * records survive kernel.reset between requests of one test (they are cleared by clear()).
 */
#[AsAlias(Broadcaster::class, public: true)]
#[When(env: 'test')]
final class RecordingBroadcaster implements Broadcaster
{
    /** @var list<array{stream: string, payload: string|array<string, mixed>}> */
    public array $broadcasts = [];

    /** @var list<array{userId: int, reconnect: bool}> */
    public array $disconnects = [];

    public function broadcast(string $stream, string|array $payload): void
    {
        $this->broadcasts[] = ['stream' => $stream, 'payload' => $payload];
    }

    public function disconnectUser(int $userId, bool $reconnect = false): void
    {
        $this->disconnects[] = ['userId' => $userId, 'reconnect' => $reconnect];
    }

    public function clear(): void
    {
        $this->broadcasts = [];
        $this->disconnects = [];
    }
}
