<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;

/** ApplicationCable::Channel and HeartbeatChannel: subscribe, confirm, nothing else. */
class BaseChannel implements Channel
{
    public function subscribed(Subscription $subscription): void
    {
    }

    public function unsubscribed(Subscription $subscription): void
    {
    }

    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        return false;
    }
}
