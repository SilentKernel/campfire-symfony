<?php

declare(strict_types=1);

namespace App\Push;

use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;

/**
 * minishlink/web-push's request building (payload encryption, VAPID headers) without its
 * sending: WebPushPool sends the requests itself, concurrently, through Symfony's HttpClient
 * (which is how the connections get pinned to checked addresses).
 */
final class WebPushRequests extends WebPush
{
    /**
     * The encrypted, signed request for one notification.
     *
     * @param array<string, mixed> $options
     *
     * @throws \ErrorException|\Throwable when the subscription's keys cannot be used
     */
    public function request(SubscriptionInterface $subscription, string $payload, array $options = []): RequestInterface
    {
        $this->notifications = [];
        $this->queueNotification($subscription, $payload, $options);
        try {
            $requests = $this->prepare($this->notifications ?? []);
        } finally {
            $this->notifications = [];
        }

        return $requests[0] ?? throw new \LogicException('No request was prepared');
    }
}
