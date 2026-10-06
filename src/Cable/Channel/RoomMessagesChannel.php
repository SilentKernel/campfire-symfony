<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\CableRepository;
use App\Cable\Server\RubyInteger;
use App\Cable\Server\Subscription;
use App\Cable\StreamNames;
use App\Rails\GlobalId;
use App\Rails\TurboStreamName;

/**
 * RoomMessagesChannel (reference/app/channels/room_messages_channel.rb): a room's message stream
 * (`<room gid param>:messages`), authorized when the subscription is made: the verified stream
 * name must point at a room the user is a member of.
 */
final class RoomMessagesChannel extends BaseChannel
{
    /** Constants a GlobalID may name that are not rooms: `only: Room` turns them away. */
    private const array OTHER_MODELS = [
        'Account', 'Ban', 'Boost', 'Current', 'Membership', 'Message', 'Push::Subscription', 'Search', 'Session', 'User', 'Webhook',
        'ActionText::RichText', 'ActiveStorage::Attachment', 'ActiveStorage::Blob', 'ActiveStorage::VariantRecord',
    ];

    public function __construct(
        private readonly CableRepository $repository,
        private readonly TurboStreamName $turboStreamName,
    ) {
    }

    /** `guarded_stream?`: true for the stream names this channel guards, whoever is asking. */
    public static function isGuardedStream(string $streamName): bool
    {
        $parts = explode(':', $streamName, 2);

        return StreamNames::MESSAGES === ($parts[1] ?? null);
    }

    public function subscribed(Subscription $subscription): void
    {
        $streamName = TurboStreamsChannel::verifiedStreamNameFromParams($this->turboStreamName, $subscription);
        if (null !== $streamName && '' !== trim($streamName) && null !== $this->subscribableRoom($subscription->currentUser()->id, $streamName)) {
            $subscription->streamFrom($streamName);
        } else {
            $subscription->reject();
        }
    }

    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        switch ($action) {
            case 'subscribed':
                $this->subscribed($subscription);

                return true;
            case 'verified_stream_name_from_params':
                TurboStreamsChannel::verifiedStreamNameFromParams($this->turboStreamName, $subscription);

                return true;
            default:
                return false;
        }
    }

    /**
     * `subscribable_room(user, stream_name)`.
     *
     * @return array{id: int, type: string}|null
     */
    public function subscribableRoom(int $userId, string $streamName): ?array
    {
        $parts = explode(':', $streamName, 2);
        if (StreamNames::MESSAGES !== ($parts[1] ?? null)) {
            return null;
        }
        $room = $this->roomFrom($parts[0]);

        return null === $room ? null : $this->repository->findRoomForUser($userId, $room['id']);
    }

    /**
     * `GlobalID::Locator.locate gid_param, only: Room`, RecordNotFound as nil. An STI class finds
     * only rooms of that type; a constant that does not exist raises (NameError in Ruby).
     *
     * @return array{id: int, type: string}|null
     */
    private function roomFrom(string $gidParam): ?array
    {
        $gid = GlobalId::parse($gidParam);
        if (null === $gid) {
            return null;
        }
        if (!CableRepository::isRoomModel($gid['model'])) {
            if (\in_array($gid['model'], self::OTHER_MODELS, true)) {
                return null;
            }
            throw new \RuntimeException(\sprintf('uninitialized constant %s', $gid['model']));
        }
        $id = RubyInteger::toI($gid['id']);

        return null === $id ? null : $this->repository->findRoom($id, 'Room' === $gid['model'] ? null : $gid['model']);
    }
}
