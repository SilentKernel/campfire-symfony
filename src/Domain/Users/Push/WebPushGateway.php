<?php

declare(strict_types=1);

namespace App\Domain\Users\Push;

use App\Entity\PushSubscription;
use App\Opengraph\PrivateNetworkGuard;
use App\Push\WebPushPool;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Resolution with the private network guard; delivery through WebPushPool, which checks the
 * endpoint again at the point of use, pins the connection to the checked address, and builds
 * WebPush::Notification's message (icon, unread badge).
 */
#[AsAlias(PushGateway::class)]
#[When('dev')]
#[When('prod')]
final readonly class WebPushGateway implements PushGateway
{
    public function __construct(
        private PrivateNetworkGuard $guard,
        private WebPushPool $pool,
    ) {
    }

    public function resolve(string $host): ?string
    {
        return $this->guard->resolve($host);
    }

    public function deliver(PushSubscription $subscription, array $payload): void
    {
        $this->pool->queue($payload, [[
            'id' => $subscription->getId(),
            'user_id' => $subscription->getUser()->getId(),
            'endpoint' => $subscription->getEndpoint(),
            'p256dh_key' => $subscription->getP256dhKey(),
            'auth_key' => $subscription->getAuthKey(),
        ]]);
    }
}
