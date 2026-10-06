<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;

/**
 * An ActionCable::Channel::Base subclass: one instance per subscription.
 *
 * Callbacks may throw Doctrine's LockWaitTimeoutException (a locked database): the connection
 * then undoes the subscription's streams and runs the whole command again a little later, so a
 * callback must do its database work before anything else it cannot repeat. Any other exception
 * is logged, as Rails logs exceptions from channel callbacks.
 */
interface Channel
{
    /** `subscribed` plus the `on_subscribe` callbacks. */
    public function subscribed(Subscription $subscription): void;

    /** `unsubscribed` plus the `on_unsubscribe` callbacks; also runs after a rejection. */
    public function unsubscribed(Subscription $subscription): void;

    /**
     * Performs a client action (`data["action"]`, or "receive"). Returns false when the channel
     * has no such public method ("Unable to process").
     *
     * @param array<array-key, mixed> $data
     */
    public function perform(string $action, array $data, Subscription $subscription): bool;
}
