<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Message> */
final class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /** `room.messages.find(id)` */
    public function findOneInRoom(Room $room, int $id): ?Message
    {
        return $this->findOneBy(['room' => $room, 'id' => $id]);
    }

    public function findOneByClientMessageId(string $clientMessageId): ?Message
    {
        return $this->findOneBy(['clientMessageId' => $clientMessageId]);
    }
}
