<?php

declare(strict_types=1);

namespace App\Entity\ActiveStorage;

use App\Entity\CreatedAtTrait;
use App\Entity\IdTrait;
use App\Entity\Timestamped;
use App\Repository\ActiveStorage\AttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ActiveStorage::Attachment: joins a blob to a polymorphic record (`record_type` is the Rails
 * class name: "User", "Message", "Account", "ActiveStorage::VariantRecord").
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'active_storage_attachments')]
final class Attachment implements Timestamped
{
    use IdTrait;
    use CreatedAtTrait;

    public function __construct(
        #[ORM\Column(name: 'name', type: Types::STRING)]
        private string $name,
        #[ORM\Column(name: 'record_type', type: Types::STRING)]
        private string $recordType,
        #[ORM\Column(name: 'record_id', type: Types::BIGINT)]
        private int $recordId,
        #[ORM\ManyToOne(targetEntity: Blob::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'blob_id', referencedColumnName: 'id', nullable: false)]
        private Blob $blob,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }

    public function getBlob(): Blob
    {
        return $this->blob;
    }
}
