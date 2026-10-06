<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;
use App\Cable\StreamNames;

/**
 * TypingNotificationsChannel (reference/app/channels/typing_notifications_channel.rb):
 * `broadcast_to @room, action:, user: current_user.slice(:id, :name)`.
 */
final class TypingNotificationsChannel extends RoomChannel
{
    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        if ('start' !== $action && 'stop' !== $action) {
            return parent::perform($action, $data, $subscription);
        }
        if (null !== $this->room) {
            $user = $subscription->currentUser();
            $subscription->broadcastTo(
                ['action' => $action, 'user' => ['id' => $user->id, 'name' => $user->name]],
                StreamNames::roomGidParam($this->room['type'], $this->room['id']),
            );
        }

        return true;
    }
}
