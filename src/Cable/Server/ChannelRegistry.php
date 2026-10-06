<?php

declare(strict_types=1);

namespace App\Cable\Server;

use App\Cable\Channel\BaseChannel;
use App\Cable\Channel\Channel;
use App\Cable\Channel\PresenceChannel;
use App\Cable\Channel\ReadRoomsChannel;
use App\Cable\Channel\RoomChannel;
use App\Cable\Channel\RoomMessagesChannel;
use App\Cable\Channel\TurboStreamsChannel;
use App\Cable\Channel\TypingNotificationsChannel;
use App\Cable\Channel\UnreadRoomsChannel;
use App\Rails\TurboStreamName;

/**
 * The channel classes a client may name in its identifier (`id_options[:channel].safe_constantize`,
 * kept when it is an ActionCable::Channel::Base subclass), by Ruby class name.
 */
final readonly class ChannelRegistry
{
    public function __construct(private CableRepository $repository, private TurboStreamName $turboStreamName)
    {
    }

    /** @return array{0: string, 1: Channel}|null the canonical class name and a fresh instance */
    public function create(string $requested): ?array
    {
        // safe_constantize accepts a leading "::"
        $name = str_starts_with($requested, '::') ? substr($requested, 2) : $requested;
        $channel = match ($name) {
            'ApplicationCable::Channel', 'HeartbeatChannel' => new BaseChannel(),
            'PresenceChannel' => new PresenceChannel($this->repository),
            'ReadRoomsChannel' => new ReadRoomsChannel(),
            'RoomChannel' => new RoomChannel($this->repository),
            'RoomMessagesChannel' => new RoomMessagesChannel($this->repository, $this->turboStreamName),
            'TypingNotificationsChannel' => new TypingNotificationsChannel($this->repository),
            'UnreadRoomsChannel' => new UnreadRoomsChannel(),
            'Turbo::StreamsChannel' => new TurboStreamsChannel($this->turboStreamName),
            default => null,
        };

        return null === $channel ? null : [$name, $channel];
    }
}
