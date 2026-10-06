<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Entity\Room;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Message::Pagination (reference/app/models/message/pagination.rb), on `room.messages`. Pages are
 * ordered by created_at (`ordered`) and hold only the messages: presentation data is loaded by
 * App\View\MessageRenderer for the fragments the cache misses (reference commit 659f957).
 */
final readonly class MessagePages
{
    public const int PAGE_SIZE = 40;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * `room.messages.last_page`.
     *
     * @return list<Message>
     */
    public function lastPage(Room $room): array
    {
        return $this->lastPageOf($this->scope($room), self::PAGE_SIZE);
    }

    /**
     * `room.messages.first_page`.
     *
     * @return list<Message>
     */
    public function firstPage(Room $room): array
    {
        return $this->firstPageOf($this->scope($room), self::PAGE_SIZE);
    }

    /**
     * `room.messages.page_before(message)`: `before(message).last_page`.
     *
     * @return list<Message>
     */
    public function pageBefore(Room $room, Message $message): array
    {
        return $this->lastPageOf($this->before($this->scope($room), $message), self::PAGE_SIZE);
    }

    /**
     * `room.messages.page_after(message)`: `after(message).first_page`.
     *
     * @return list<Message>
     */
    public function pageAfter(Room $room, Message $message): array
    {
        return $this->firstPageOf($this->after($this->scope($room), $message), self::PAGE_SIZE);
    }

    /**
     * `room.messages.page_around(message)`: the page before it, the message, the page after it.
     *
     * @return list<Message>
     */
    public function pageAround(Room $room, Message $message): array
    {
        return [...$this->pageBefore($room, $message), $message, ...$this->pageAfter($room, $message)];
    }

    /**
     * `room.messages.page_created_since(time)`: `where("created_at > ?", time).first_page`.
     *
     * @return list<Message>
     */
    public function pageCreatedSince(Room $room, \DateTimeInterface $time): array
    {
        return $this->firstPageOf($this->scope($room)->andWhere('m.createdAt > :since')->setParameter('since', $time, RailsDateTimeType::NAME), self::PAGE_SIZE);
    }

    /**
     * `room.messages.page_updated_since(time)`: `where("updated_at > ?", time).last_page`.
     *
     * @return list<Message>
     */
    public function pageUpdatedSince(Room $room, \DateTimeInterface $time): array
    {
        return $this->lastPageOf($this->scope($room)->andWhere('m.updatedAt > :since')->setParameter('since', $time, RailsDateTimeType::NAME), self::PAGE_SIZE);
    }

    /** `room.messages.find(id)`; null where Rails raises RecordNotFound. */
    public function find(Room $room, ?int $id): ?Message
    {
        if (null === $id) {
            return null;
        }
        $message = $this->scope($room)->andWhere('m.id = :id')->setParameter('id', $id)->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $message instanceof Message ? $message : null;
    }

    /** `paged?` */
    public function isPaged(Room $room): bool
    {
        return $this->count($room) > self::PAGE_SIZE;
    }

    public function count(Room $room): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messages WHERE room_id = ?', [$room->getId()]);
    }

    /** `before(message)`: created_at earlier than the message's. */
    public function before(QueryBuilder $qb, Message $message): QueryBuilder
    {
        return $qb->andWhere('m.createdAt < :before')->setParameter('before', $message->getCreatedAt(), RailsDateTimeType::NAME);
    }

    /** `after(message)`: created_at later than the message's. */
    public function after(QueryBuilder $qb, Message $message): QueryBuilder
    {
        return $qb->andWhere('m.createdAt > :after')->setParameter('after', $message->getCreatedAt(), RailsDateTimeType::NAME);
    }

    /** `room.messages` */
    public function scope(Room $room): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('m')->from(Message::class, 'm')
            ->where('m.room = :room')->setParameter('room', $room->getId());
    }

    /**
     * `ordered.first(size)`.
     *
     * @return list<Message>
     */
    public function firstPageOf(QueryBuilder $qb, int $size): array
    {
        /** @var list<Message> $messages */
        $messages = array_values($qb->orderBy('m.createdAt', 'ASC')->setMaxResults($size)->getQuery()->getResult());

        return $messages;
    }

    /**
     * `ordered.last(size)`: Active Record reverses the order, limits, and reverses the records.
     *
     * @return list<Message>
     */
    public function lastPageOf(QueryBuilder $qb, int $size): array
    {
        /** @var list<Message> $messages */
        $messages = array_reverse(array_values($qb->orderBy('m.createdAt', 'DESC')->setMaxResults($size)->getQuery()->getResult()));

        return $messages;
    }
}
