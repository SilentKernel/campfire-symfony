<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RichText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RichText> */
final class RichTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RichText::class);
    }

    /** `record.body`: the rich text named $name of the polymorphic record. */
    public function findFor(string $recordType, int $recordId, string $name = 'body'): ?RichText
    {
        return $this->findOneBy(['recordType' => $recordType, 'recordId' => $recordId, 'name' => $name]);
    }

    /**
     * The bodies of several records of one type (the `with_rich_text_body` preload), keyed by record id.
     *
     * @param list<int> $recordIds
     *
     * @return array<int, RichText>
     */
    public function findForRecords(string $recordType, array $recordIds, string $name = 'body'): array
    {
        if ([] === $recordIds) {
            return [];
        }
        $byRecord = [];
        foreach ($this->findBy(['recordType' => $recordType, 'recordId' => $recordIds, 'name' => $name]) as $richText) {
            $byRecord[$richText->getRecordId()] = $richText;
        }

        return $byRecord;
    }
}
