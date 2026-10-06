<?php

declare(strict_types=1);

namespace App\Repository\ActiveStorage;

use App\Entity\ActiveStorage\Blob;
use App\Entity\ActiveStorage\VariantRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<VariantRecord> */
final class VariantRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VariantRecord::class);
    }

    /** `blob.variant_records.find_by(variation_digest:)` */
    public function findOneFor(Blob $blob, string $variationDigest): ?VariantRecord
    {
        return $this->findOneBy(['blob' => $blob, 'variationDigest' => $variationDigest]);
    }
}
