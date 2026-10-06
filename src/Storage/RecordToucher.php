<?php

declare(strict_types=1);

namespace App\Storage;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Account;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * `ActiveStorage::Attachment belongs_to :record, touch: true`: creating, destroying or touching an
 * attachment touches its record, and whatever that record touches in turn (`Message belongs_to
 * :room, touch: true`). Records without `updated_at` (blobs, variant records) are left alone.
 * Entities already loaded get the same timestamp, without being marked dirty.
 */
final readonly class RecordToucher
{
    private const array TABLES = [
        'Message' => ['messages', Message::class],
        'User' => ['users', User::class],
        'Account' => ['accounts', Account::class],
    ];

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function touch(string $recordType, int $recordId): void
    {
        if (!isset(self::TABLES[$recordType])) {
            return;
        }
        [$table, $class] = self::TABLES[$recordType];
        $now = $this->clock->now();
        $this->connection->update($table, ['updated_at' => RailsDateTimeType::format($now)], ['id' => $recordId]);
        $this->sync($class, $recordId, $now);

        if ('Message' === $recordType) {
            $roomId = $this->connection->fetchOne('SELECT room_id FROM messages WHERE id = ?', [$recordId]);
            if (false !== $roomId && null !== $roomId) {
                $this->connection->update('rooms', ['updated_at' => RailsDateTimeType::format($now)], ['id' => $roomId]);
                $this->sync(Room::class, (int) $roomId, $now);
            }
        }
    }

    /** @param class-string $class */
    private function sync(string $class, int $id, \DateTimeImmutable $now): void
    {
        $unitOfWork = $this->em->getUnitOfWork();
        $entity = $unitOfWork->tryGetById($id, $this->em->getClassMetadata($class)->rootEntityName);
        if (\is_object($entity) && method_exists($entity, 'setUpdatedAt')) {
            $entity->setUpdatedAt($now);
            $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), 'updatedAt', $now);
        }
    }
}
