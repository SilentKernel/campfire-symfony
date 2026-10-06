<?php

declare(strict_types=1);

namespace App\Entity\ActiveStorage;

use App\Entity\CreatedAtTrait;
use App\Entity\IdTrait;
use App\Entity\Timestamped;
use App\Repository\ActiveStorage\BlobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ActiveStorage::Blob (activestorage/app/models/active_storage/blob.rb). The file lives on disk at
 * `storage/files/<key[0..1]>/<key[2..3]>/<key>`; `metadata` is the JSON text Rails' `store`
 * coder writes (`{"identified":true,"width":640,"height":640,"analyzed":true}`).
 */
#[ORM\Entity(repositoryClass: BlobRepository::class)]
#[ORM\Table(name: 'active_storage_blobs')]
final class Blob implements Timestamped
{
    use IdTrait;
    use CreatedAtTrait;

    public const RECORD_TYPE = 'ActiveStorage::Blob';

    #[ORM\Column(name: 'content_type', type: Types::STRING, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'metadata', type: Types::TEXT, nullable: true)]
    private ?string $metadata = null;

    #[ORM\Column(name: 'checksum', type: Types::STRING, nullable: true)]
    private ?string $checksum = null;

    public function __construct(
        #[ORM\Column(name: 'key', type: Types::STRING)]
        private string $key,
        #[ORM\Column(name: 'filename', type: Types::STRING)]
        private string $filename,
        #[ORM\Column(name: 'byte_size', type: Types::BIGINT)]
        private int $byteSize,
        #[ORM\Column(name: 'service_name', type: Types::STRING)]
        private string $serviceName = 'local',
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function setByteSize(int $byteSize): static
    {
        $this->byteSize = $byteSize;

        return $this;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    /** The base64 MD5 digest of the file (`checksum`). */
    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): static
    {
        $this->checksum = $checksum;

        return $this;
    }

    /** The raw `metadata` column, exactly as stored. */
    public function getMetadataJson(): ?string
    {
        return $this->metadata;
    }

    /** Writes the column verbatim: encode with ActiveSupport's JSON rules (App\Rails) first. */
    public function setMetadataJson(?string $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * The decoded metadata (`blob.metadata`), `[]` when the column is empty or not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        if (null === $this->metadata || '' === $this->metadata) {
            return [];
        }
        $decoded = json_decode($this->metadata, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->contentType ?? '', 'image');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->contentType ?? '', 'video');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->contentType ?? '', 'audio');
    }

    public function isText(): bool
    {
        return str_starts_with($this->contentType ?? '', 'text');
    }

    /** `ActiveStorage::Service::DiskService#path_for`, relative to the service root. */
    public function getRelativePath(): string
    {
        return substr($this->key, 0, 2).'/'.substr($this->key, 2, 2).'/'.$this->key;
    }
}
