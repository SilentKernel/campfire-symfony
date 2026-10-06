<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;
use App\Cable\StreamNames;
use App\Rails\RailsJson;

/**
 * PresenceChannel (reference/app/channels/presence_channel.rb): a RoomChannel that keeps the
 * membership's connection count (Membership::Connectable) while subscribed, and tells the user's
 * other windows that the room has been read.
 */
final class PresenceChannel extends RoomChannel
{
    /** `subscribed`, then `on_subscribe :present, unless: :subscription_rejected?`. */
    public function subscribed(Subscription $subscription): void
    {
        parent::subscribed($subscription);
        if (!$subscription->isRejected()) {
            $this->present($subscription);
        }
    }

    /** `on_unsubscribe :absent, unless: :subscription_rejected?`. */
    public function unsubscribed(Subscription $subscription): void
    {
        if (!$subscription->isRejected()) {
            $this->absent($subscription);
        }
    }

    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        match ($action) {
            'present' => $this->present($subscription),
            'absent' => $this->absent($subscription),
            'refresh' => $this->refresh($subscription),
            default => null,
        };

        return \in_array($action, ['present', 'absent', 'refresh'], true) || parent::perform($action, $data, $subscription);
    }

    /** `membership.present`, then `broadcast_read_room`. */
    private function present(Subscription $subscription): void
    {
        $membership = $this->membership($subscription);
        $this->repository->present($membership);

        $userId = $subscription->currentUser()->id;
        $subscription->server()->broadcast(StreamNames::readRooms($userId), RailsJson::encode(['room_id' => $membership['room_id']]));
    }

    private function absent(Subscription $subscription): void
    {
        $this->repository->disconnected($this->membership($subscription));
    }

    private function refresh(Subscription $subscription): void
    {
        $this->repository->refreshConnection($this->membership($subscription));
    }

    /**
     * `@room.memberships.find_by(user: current_user)`: nil once the membership is gone, and the
     * callback raises (NoMethodError) as in Rails.
     *
     * @return array{id: int, room_id: int, connections: int, connected_at: ?string}
     */
    private function membership(Subscription $subscription): array
    {
        $membership = null === $this->room ? null : $this->repository->findMembership($this->room['id'], $subscription->currentUser()->id);

        return $membership ?? throw new \RuntimeException('undefined method for nil (membership)');
    }
}
