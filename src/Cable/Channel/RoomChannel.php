<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\CableRepository;
use App\Cable\Server\RubyInteger;
use App\Cable\Server\Subscription;
use App\Cable\StreamNames;

/**
 * RoomChannel (reference/app/channels/room_channel.rb): `stream_for @room` when the user is a
 * member, else `reject`. PresenceChannel and TypingNotificationsChannel inherit it, and each
 * streams from its own `<channel_name>:<room gid param>`.
 */
class RoomChannel extends BaseChannel
{
    /** @var array{id: int, type: string}|null */
    protected ?array $room = null;

    public function __construct(protected readonly CableRepository $repository)
    {
    }

    public function subscribed(Subscription $subscription): void
    {
        $this->room = $this->findRoom($subscription);
        if (null !== $this->room) {
            $subscription->streamFor(StreamNames::roomGidParam($this->room['type'], $this->room['id']));
        } else {
            $subscription->reject();
        }
    }

    /** `subscribed` is public in the Ruby class, so it is an action too. */
    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        if ('subscribed' === $action) {
            $this->subscribed($subscription);

            return true;
        }

        return false;
    }

    /**
     * `current_user.rooms.find_by(id: params[:room_id])`.
     *
     * @return array{id: int, type: string}|null
     */
    private function findRoom(Subscription $subscription): ?array
    {
        $roomId = RubyInteger::cast($subscription->param('room_id'));

        return null === $roomId ? null : $this->repository->findRoomForUser($subscription->currentUser()->id, $roomId);
    }
}
