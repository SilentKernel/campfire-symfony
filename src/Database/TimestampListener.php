<?php

declare(strict_types=1);

namespace App\Database;

use App\Entity\Timestamped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Clock\ClockInterface;

/**
 * ActiveRecord::Timestamp for Timestamped entities, at flush time (when Rails' save runs):
 *
 * - on create, created_at and updated_at get the current time unless already set;
 * - on update, updated_at gets the current time only when other attributes changed and
 *   updated_at itself wasn't assigned (`should_record_timestamps?`, `timestamp_attributes_for_update_in_model`).
 *   Doctrine schedules no update for an unchanged entity, so a no-op save leaves it alone, as in Rails.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class TimestampListener
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $now = null;

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Timestamped) {
                continue;
            }
            $now ??= $this->now();
            $metadata = $em->getClassMetadata($entity::class);
            $changed = false;
            foreach (['createdAt', 'updatedAt'] as $field) {
                if ($metadata->hasField($field) && null === $metadata->getFieldValue($entity, $field)) {
                    $metadata->setFieldValue($entity, $field, $now);
                    $changed = true;
                }
            }
            if ($changed) {
                $uow->recomputeSingleEntityChangeSet($metadata, $entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Timestamped) {
                continue;
            }
            $metadata = $em->getClassMetadata($entity::class);
            if (!$metadata->hasField('updatedAt') || !$this->recordsTimestamp($uow, $entity)) {
                continue;
            }
            $now ??= $this->now();
            $metadata->setFieldValue($entity, 'updatedAt', $now);
            $uow->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    /** Some attribute other than updated_at changed (association changes count, as in Rails). */
    private function recordsTimestamp(UnitOfWork $uow, object $entity): bool
    {
        $changeSet = $uow->getEntityChangeSet($entity);

        return [] !== $changeSet && !\array_key_exists('updatedAt', $changeSet);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
