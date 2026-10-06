<?php

declare(strict_types=1);

namespace App\Cable;

use App\Entity\Room;
use App\Entity\User;
use App\Rails\GlobalId;
use App\Rails\TurboStreamName;

/**
 * The Action Cable broadcasting names the Rails app uses.
 *
 * - Turbo streams (`turbo_stream_from` / `broadcast_*_to`): each streamable is its `to_gid_param`
 *   (records, with the STI class: `gid://campfire/Rooms::Open/1`) or itself (symbols), joined
 *   with ":" (turbo-rails Turbo::Streams::StreamName#stream_name_from).
 * - `stream_for` / `broadcast_to` in a channel: `<channel_name>:<gid param>`
 *   (ActionCable::Channel::Broadcasting#broadcasting_for), so RoomChannel, PresenceChannel and
 *   TypingNotificationsChannel each have their own room stream.
 * - Plain names from reference/app/channels (read_rooms_channel.rb, unread_rooms_channel.rb).
 *
 * The static methods only build names; signed() signs one for `<turbo-cable-stream-source>`.
 */
final readonly class StreamNames
{
    public const string ROOMS = 'rooms';
    public const string MESSAGES = 'messages';

    public function __construct(private TurboStreamName $turboStreamName)
    {
    }

    /** Turbo::StreamsChannel.signed_stream_name(name). */
    public function signed(string $streamName): string
    {
        return $this->turboStreamName->sign($streamName);
    }

    /** `:rooms`, the sidebar's shared stream (users/sidebars/show.html.erb). */
    public static function rooms(): string
    {
        return self::ROOMS;
    }

    /** `[Current.user, :rooms]`. */
    public static function userRooms(User|int $user): string
    {
        return self::userGidParam($user).':'.self::ROOMS;
    }

    /** `[room, :messages]` (rooms/show.html.erb, Message::Broadcasts), guarded by RoomMessagesChannel. */
    public static function roomMessages(Room $room): string
    {
        return self::roomGidParam($room).':'.self::MESSAGES;
    }

    /** UnreadRoomsChannel.stream_name_for(user_id). */
    public static function unreadRooms(int $userId): string
    {
        return 'user_'.$userId.'_unreads';
    }

    /** ReadRoomsChannel: "user_#{id}_reads" (also PresenceChannel#broadcast_read_room). */
    public static function readRooms(int $userId): string
    {
        return 'user_'.$userId.'_reads';
    }

    /** RoomChannel `stream_for @room`. */
    public static function roomChannel(Room $room): string
    {
        return 'room:'.self::roomGidParam($room);
    }

    /** PresenceChannel `stream_for @room` (it inherits RoomChannel#subscribed). */
    public static function presence(Room $room): string
    {
        return 'presence:'.self::roomGidParam($room);
    }

    /** TypingNotificationsChannel `stream_for @room` / `broadcast_to @room`. */
    public static function typing(Room $room): string
    {
        return 'typing_notifications:'.self::roomGidParam($room);
    }

    /** `room.to_gid_param` with the STI class name ("Rooms::Open"). */
    public static function roomGidParam(Room|string $roomOrType, ?int $id = null): string
    {
        if ($roomOrType instanceof Room) {
            return GlobalId::param(GlobalId::gid($roomOrType->getType(), $roomOrType->getId()));
        }

        return GlobalId::param(GlobalId::gid($roomOrType, (int) $id));
    }

    public static function userGidParam(User|int $user): string
    {
        return GlobalId::param(GlobalId::gid('User', $user instanceof User ? $user->getId() : $user));
    }

    /**
     * ActionCable::Channel::Naming.channel_name + broadcasting_for: "<channel_name>:<parts>".
     * The channel name is the class name without "Channel", "::" as ":", underscored.
     */
    public static function broadcastingFor(string $channelClass, string ...$parts): string
    {
        return implode(':', [self::channelName($channelClass), ...$parts]);
    }

    public static function channelName(string $channelClass): string
    {
        $name = str_ends_with($channelClass, 'Channel') ? substr($channelClass, 0, -7) : $channelClass;
        $name = str_replace('::', '/', $name);
        $name = (string) preg_replace('/([A-Z\d]+)([A-Z][a-z])/', '$1_$2', $name);
        $name = (string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $name);

        return str_replace('/', ':', strtolower(str_replace('-', '_', $name)));
    }
}
