<?php

declare(strict_types=1);

namespace App\Entity\ActiveStorage;

use App\Entity\IdTrait;
use App\Repository\ActiveStorage\VariantRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ActiveStorage::VariantRecord: a processed variant of a blob, keyed by the digest of its
 * transformations. The variant's own file is the blob of its "image" attachment
 * (record_type "ActiveStorage::VariantRecord"). No timestamps.
 */
#[ORM\Entity(repositoryClass: VariantRecordRepository::class)]
#[ORM\Table(name: 'active_storage_variant_records')]
final class VariantRecord
{
    use IdTrait;

    public const RECORD_TYPE = 'ActiveStorage::VariantRecord';

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Blob::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'blob_id', referencedColumnName: 'id', nullable: false)]
        private Blob $blob,
        #[ORM\Column(name: 'variation_digest', type: Types::STRING)]
        private string $variationDigest,
    ) {
    }

    public function getBlob(): Blob
    {
        return $this->blob;
    }

    public function getVariationDigest(): string
    {
        return $this->variationDigest;
    }
}
