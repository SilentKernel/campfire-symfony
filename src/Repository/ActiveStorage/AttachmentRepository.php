<?php

declare(strict_types=1);

namespace App\Repository\ActiveStorage;

use App\Entity\ActiveStorage\Attachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Attachment> */
final class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    /** `record.<name>_attachment` (`has_one_attached`), with its blob. */
    public function findOneFor(string $recordType, int $recordId, string $name): ?Attachment
    {
        return $this->createQueryBuilder('a')
            ->addSelect('b')->join('a.blob', 'b')
            ->where('a.recordType = :type AND a.recordId = :id AND a.name = :name')
            ->setParameter('type', $recordType)->setParameter('id', $recordId)->setParameter('name', $name)
            ->orderBy('a.id', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The `<name>_attachment` of several records of one type, blobs preloaded, keyed by record id.
     *
     * @param list<int> $recordIds
     *
     * @return array<int, Attachment>
     */
    public function findForRecords(string $recordType, array $recordIds, string $name): array
    {
        if ([] === $recordIds) {
            return [];
        }
        $attachments = $this->createQueryBuilder('a')
            ->addSelect('b')->join('a.blob', 'b')
            ->where('a.recordType = :type AND a.recordId IN (:ids) AND a.name = :name')
            ->setParameter('type', $recordType)->setParameter('ids', $recordIds)->setParameter('name', $name)
            ->orderBy('a.id', 'ASC')
            ->getQuery()->getResult();
        $byRecord = [];
        foreach ($attachments as $attachment) {
            $byRecord[$attachment->getRecordId()] ??= $attachment;
        }

        return $byRecord;
    }
}
