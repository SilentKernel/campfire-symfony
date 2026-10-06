<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Messages;

use App\Domain\Messages\MessagePages;
use App\Entity\Message;
use App\Entity\Room;
use App\Tests\Support\CampfireTestCase;

/**
 * Message::Pagination (reference/app/models/message/pagination.rb) on the seed's All Talk room
 * (131 messages) and Designers (31).
 */
final class MessagePagesTest extends CampfireTestCase
{
    public function testLastPageIsTheNewestFortyOldestFirst(): void
    {
        $room = $this->room('rooms.watercooler');
        $page = $this->pages()->lastPage($room);

        self::assertSame(MessagePages::PAGE_SIZE, \count($page));
        self::assertSame($this->ids('SELECT id FROM messages WHERE room_id = ? ORDER BY created_at DESC LIMIT 40', $room, true), self::idsOf($page));
        self::assertTrue($this->pages()->isPaged($room));
        self::assertFalse($this->pages()->isPaged($this->room('rooms.designers')));
        self::assertSame(31, \count($this->pages()->lastPage($this->room('rooms.designers'))));
    }

    public function testPagesBeforeAndAfterAMessage(): void
    {
        $room = $this->room('rooms.watercooler');
        $all = $this->ids('SELECT id FROM messages WHERE room_id = ? ORDER BY created_at', $room);
        $anchor = $this->message($all[60]);

        self::assertSame(\array_slice($all, 20, 40), self::idsOf($this->pages()->pageBefore($room, $anchor)));
        self::assertSame(\array_slice($all, 61, 40), self::idsOf($this->pages()->pageAfter($room, $anchor)));
        self::assertSame(\array_slice($all, 20, 81), self::idsOf($this->pages()->pageAround($room, $anchor)));
        self::assertSame([], $this->pages()->pageBefore($room, $this->message($all[0])));
        self::assertSame(\array_slice($all, 0, 10), self::idsOf($this->pages()->pageBefore($room, $this->message($all[10]))));
        self::assertSame(\array_slice($all, 1, 40), self::idsOf($this->pages()->firstPageOf($this->pages()->after($this->pages()->scope($room), $this->message($all[0])), 40)));
    }

    public function testPagesSinceATime(): void
    {
        $room = $this->room('rooms.designers');
        $since = new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            $this->ids("SELECT id FROM messages WHERE room_id = ? AND created_at > '2026-03-01 12:00:00' ORDER BY created_at", $room),
            self::idsOf($this->pages()->pageCreatedSince($room, $since)),
        );
        self::assertSame(
            $this->ids("SELECT id FROM messages WHERE room_id = ? AND updated_at > '2026-03-01 12:00:00' ORDER BY created_at", $room),
            self::idsOf($this->pages()->pageUpdatedSince($room, $since)),
        );
    }

    public function testFindIsScopedToTheRoom(): void
    {
        self::assertNotNull($this->pages()->find($this->room('rooms.designers'), self::id('messages.plain')));
        self::assertNull($this->pages()->find($this->room('rooms.watercooler'), self::id('messages.plain')));
        self::assertNull($this->pages()->find($this->room('rooms.designers'), null));
    }

    private function pages(): MessagePages
    {
        $pages = static::getContainer()->get(MessagePages::class);
        \assert($pages instanceof MessagePages);

        return $pages;
    }

    private function room(string $label): Room
    {
        $room = $this->em()->find(Room::class, self::id($label));
        \assert($room instanceof Room);

        return $room;
    }

    private function message(int $id): Message
    {
        $message = $this->em()->find(Message::class, $id);
        \assert($message instanceof Message);

        return $message;
    }

    /** @return list<int> */
    private function ids(string $sql, Room $room, bool $reverse = false): array
    {
        $ids = array_map(intval(...), $this->connection()->fetchFirstColumn($sql, [$room->getId()]));

        return $reverse ? array_reverse($ids) : $ids;
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<int>
     */
    private static function idsOf(array $messages): array
    {
        return array_map(static fn (Message $message): int => $message->getId(), $messages);
    }
}
