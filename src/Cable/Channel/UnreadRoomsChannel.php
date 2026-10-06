<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;
use App\Cable\StreamNames;

/** UnreadRoomsChannel (reference/app/channels/unread_rooms_channel.rb): scoped per user. */
final class UnreadRoomsChannel extends BaseChannel
{
    public function subscribed(Subscription $subscription): void
    {
        $subscription->streamFrom(StreamNames::unreadRooms($subscription->currentUser()->id));
    }

    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        if ('subscribed' === $action) {
            $this->subscribed($subscription);

            return true;
        }

        return false;
    }
}
