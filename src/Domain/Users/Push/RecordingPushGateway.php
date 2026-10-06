<?php

declare(strict_types=1);

namespace App\Domain\Users\Push;

use App\Entity\PushSubscription;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The test environment's gateway: no network. Permitted push hosts resolve to a public address
 * unless listed in $unresolvable; deliveries are recorded.
 */
#[AsAlias(PushGateway::class, public: true)]
#[When('test')]
final class RecordingPushGateway implements PushGateway
{
    /** @var list<array{subscription_id: int, payload: array{title: string, body: string, path: string}}> */
    public array $deliveries = [];

    /** @var list<string> */
    public array $unresolvable = [];

    public function resolve(string $host): ?string
    {
        return \in_array($host, $this->unresolvable, true) ? null : '142.250.0.1';
    }

    public function deliver(PushSubscription $subscription, array $payload): void
    {
        $this->deliveries[] = ['subscription_id' => $subscription->getId(), 'payload' => $payload];
    }
}
