<?php

declare(strict_types=1);

namespace App\Domain\Users\Push;

use App\Entity\PushSubscription;

/**
 * What Push::Subscription needs from the network: `resolved_endpoint_ip`
 * (RestrictedHTTP::PrivateNetworkGuard.resolve) and WebPush delivery. WebPushGateway is the
 * application's; tests use RecordingPushGateway.
 */
interface PushGateway
{
    /** The first public address of $host, or null (blocked, malformed or unresolvable). */
    public function resolve(string $host): ?string;

    /**
     * `subscription.notification(title:, body:, path:).deliver`.
     *
     * @param array{title: string, body: string, path: string} $payload
     */
    public function deliver(PushSubscription $subscription, array $payload): void;
}
