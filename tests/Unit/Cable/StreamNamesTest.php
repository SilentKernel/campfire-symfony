<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cable;

use App\Cable\StreamNames;
use App\Entity\Room;
use App\Entity\Rooms\Closed;
use App\Entity\Rooms\Open;
use App\Entity\User;
use App\Rails\TurboStreamName;
use App\Tests\Unit\Rails\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamNamesTest extends TestCase
{
    public function testTurboStreamNamesMatchTheVectors(): void
    {
        $names = new StreamNames(new TurboStreamName(Vectors::keys()));
        $room = self::withId(new Open(self::withId(new User('David'), 1), 'Open'), 1);

        self::assertSame('Z2lkOi8vY2FtcGZpcmUvUm9vbXM6Ok9wZW4vMQ:messages', StreamNames::roomMessages($room));
        self::assertSame('Z2lkOi8vY2FtcGZpcmUvVXNlci8x:rooms', StreamNames::userRooms(1));
        self::assertSame('rooms', StreamNames::rooms());

        foreach (Vectors::cases('turbo_stream_names.generate') as [$case]) {
            self::assertSame($case['signed'], $names->signed($case['stream_name']));
        }
    }

    public function testChannelStreamsAreNamedPerChannelClass(): void
    {
        $room = self::withId(new Closed(self::withId(new User('David'), 1), 'Closed'), 7);
        $gid = StreamNames::roomGidParam('Rooms::Closed', 7);

        self::assertSame($gid, StreamNames::roomGidParam($room));
        self::assertSame('room:'.$gid, StreamNames::roomChannel($room));
        self::assertSame('presence:'.$gid, StreamNames::presence($room));
        self::assertSame('typing_notifications:'.$gid, StreamNames::typing($room));
        self::assertSame('user_7_reads', StreamNames::readRooms(7));
        self::assertSame('user_7_unreads', StreamNames::unreadRooms(7));
    }

    /** @return iterable<array{string, string}> */
    public static function channelNames(): iterable
    {
        yield ['RoomChannel', 'room'];
        yield ['TypingNotificationsChannel', 'typing_notifications'];
        yield ['Turbo::StreamsChannel', 'turbo:streams'];
        yield ['HTMLChannel', 'html'];
        yield ['HTMLParserChannel', 'html_parser'];
        yield ['ApplicationCable::Channel', 'application_cable:']; // delete_suffix("Channel") first
    }

    #[DataProvider('channelNames')]
    public function testChannelName(string $class, string $name): void
    {
        self::assertSame($name, StreamNames::channelName($class));
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function withId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity instanceof Room ? Room::class : $entity::class, 'id');
        $property->setValue($entity, $id);

        return $entity;
    }
}
