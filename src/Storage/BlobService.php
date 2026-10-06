<?php

declare(strict_types=1);

namespace App\Storage;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\ActiveStorage\Blob;
use App\Rails\RailsJson;
use App\Storage\Job\AnalyzeBlob;
use App\Storage\Job\PurgeBlob;
use App\Storage\Marcel\Marcel;
use App\Storage\Processing\Analyzer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ActiveStorage::Blob's behaviour (activestorage/app/models/active_storage/blob.rb and its
 * Analyzable, Identifiable, Representable and Servable concerns) over the Blob entity and the
 * "local" DiskService.
 */
#[Autoconfigure(public: true)]
final readonly class BlobService
{
    /** `ActiveStorage::Blob::MINIMUM_TOKEN_LENGTH` */
    public const int KEY_LENGTH = 28;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private DiskService $disk,
        private RecordToucher $toucher,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /** `Blob.create_and_upload!(io: file, filename: file.original_filename, content_type: file.content_type)` */
    public function createFromUpload(UploadedFile $file): Blob
    {
        return $this->createFromFile($file->getPathname(), $file->getClientOriginalName(), $file->getClientMimeType());
    }

    /** `Blob.create_and_upload!(io: StringIO.new(bytes), filename:, content_type:)` */
    public function createFromBytes(string $bytes, string $filename, ?string $contentType = null): Blob
    {
        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            return $this->create(
                new Filename($filename),
                $this->identify(substr($bytes, 0, Marcel::magicPrefixLength()), $filename, $contentType, true),
                \strlen($bytes),
                base64_encode(md5($bytes, true)),
                $stream,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * `Blob.create_and_upload!` for a local file. With `$identify` false a declared content type is
     * kept as is (`identify: false`).
     */
    public function createFromFile(string $path, string $filename, ?string $contentType = null, bool $identify = true): Blob
    {
        $head = (string) @file_get_contents($path, false, null, 0, Marcel::magicPrefixLength());
        $size = filesize($path);
        if (false === $size) {
            throw new FileNotFound($path);
        }

        return $this->create(new Filename($filename), $this->identify($head, $filename, $contentType, $identify), $size, DiskService::checksumFile($path), $path);
    }

    /**
     * `Blob.create_before_direct_upload!(filename:, byte_size:, checksum:, content_type:, metadata:)`:
     * the row only; the client then PUTs the bytes to the disk service.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function createBeforeDirectUpload(string $filename, int $byteSize, string $checksum, ?string $contentType, ?array $metadata): Blob
    {
        return $this->transactions->transaction(function () use ($filename, $byteSize, $checksum, $contentType, $metadata): Blob {
            $this->connection->insert('active_storage_blobs', [
                'key' => self::generateKey(),
                'filename' => $filename,
                'content_type' => $contentType,
                'metadata' => null === $metadata ? null : ([] === $metadata ? '{}' : RailsJson::encode($metadata)),
                'service_name' => DiskService::NAME,
                'byte_size' => $byteSize,
                'checksum' => $checksum,
                'created_at' => RailsDateTimeType::format($this->clock->now()),
            ]);

            return $this->em->find(Blob::class, (int) $this->connection->lastInsertId()) ?? throw new \LogicException('The new blob vanished.');
        });
    }

    /**
     * `blob.analyze`: `update!(metadata: metadata.merge(analyzer.metadata.merge(analyzed: true)))`,
     * then `touch_attachments` (after_update).
     */
    public function analyze(Blob $blob): void
    {
        $analyzer = Analyzer::for($blob->getContentType());
        $extracted = Analyzer::NULL === $analyzer ? [] : $this->withLocalFile($blob, static fn (string $path): array => Analyzer::metadata($analyzer, $path));
        $metadata = self::mergeMetadata($blob->getMetadataJson(), $extracted + ['analyzed' => true]);

        $this->transactions->transaction(function () use ($blob, $metadata): void {
            $blob->setMetadataJson($metadata);
            $this->connection->update('active_storage_blobs', ['metadata' => $metadata], ['id' => $blob->getId()]);
            $this->touchAttachments($blob);
        });
    }

    /** `blob.analyze_later`: an AnalyzeJob, except for the NullAnalyzer, which runs inline. */
    public function analyzeLater(Blob $blob): void
    {
        if (Analyzer::analyzeLater(Analyzer::for($blob->getContentType()))) {
            $this->transactions->afterCommit(fn () => $this->bus->dispatch(new AnalyzeBlob($blob->getId())));
        } else {
            $this->analyze($blob);
        }
    }

    public function isAnalyzed(Blob $blob): bool
    {
        $analyzed = $blob->getMetadata()['analyzed'] ?? null;

        return null !== $analyzed && false !== $analyzed;
    }

    /**
     * `blob.purge`: destroy the row (variant records and the preview image go with it, their own
     * blobs purged later), then delete the files. A blob that is still attached is left alone
     * (`ActiveRecord::InvalidForeignKey` is rescued).
     */
    public function purge(Blob $blob): void
    {
        $blobId = $blob->getId();
        $dependents = $this->transactions->transaction(function () use ($blobId): ?array {
            if (false !== $this->connection->fetchOne('SELECT 1 FROM active_storage_attachments WHERE blob_id = ? LIMIT 1', [$blobId])) {
                return null;
            }
            // has_many :variant_records (before_destroy destroy_all) → their `image` attachments,
            // and has_one_attached :preview_image: dependent: :purge_later.
            $recordIds = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM active_storage_variant_records WHERE blob_id = ?', [$blobId]));
            $dependentBlobIds = array_map(intval(...), $this->connection->fetchFirstColumn(
                "SELECT blob_id FROM active_storage_attachments WHERE (record_type = 'ActiveStorage::VariantRecord' AND record_id IN (SELECT id FROM active_storage_variant_records WHERE blob_id = ?)) OR (record_type = 'ActiveStorage::Blob' AND record_id = ?)",
                [$blobId, $blobId],
            ));
            $this->connection->executeStatement(
                "DELETE FROM active_storage_attachments WHERE (record_type = 'ActiveStorage::VariantRecord' AND record_id IN (SELECT id FROM active_storage_variant_records WHERE blob_id = ?)) OR (record_type = 'ActiveStorage::Blob' AND record_id = ?)",
                [$blobId, $blobId],
            );
            if ([] !== $recordIds) {
                $this->connection->executeStatement('DELETE FROM active_storage_variant_records WHERE blob_id = ?', [$blobId]);
            }
            $this->connection->delete('active_storage_blobs', ['id' => $blobId]);
            foreach ($dependentBlobIds as $dependentId) {
                $this->transactions->afterCommit(fn () => $this->bus->dispatch(new PurgeBlob($dependentId)));
            }

            return $dependentBlobIds;
        });
        if (null === $dependents) {
            return;
        }
        if ($this->em->contains($blob)) {
            $this->em->detach($blob);
        }
        $this->disk->delete($blob->getKey());
        if ($blob->isImage()) {
            $this->disk->deletePrefixed('variants/'.$blob->getKey().'/');
        }
    }

    /** `blob.purge_later`: an ActiveStorage::PurgeJob. */
    public function purgeLater(Blob $blob): void
    {
        $blobId = $blob->getId();
        $this->transactions->afterCommit(fn () => $this->bus->dispatch(new PurgeBlob($blobId)));
    }

    /**
     * `blob.open { |file| ... }`: a checksum-verified local copy named `ActiveStorage-<id>-…<.ext>`,
     * deleted afterwards.
     *
     * @template T
     *
     * @param callable(string): T $work
     *
     * @return T
     */
    public function withLocalFile(Blob $blob, callable $work): mixed
    {
        $source = $this->disk->pathFor($blob->getKey());
        if (!is_file($source)) {
            throw new FileNotFound($blob->getKey());
        }
        $copy = Processing\Vips::tempfile('ActiveStorage-'.$blob->getId().'-', (new Filename($blob->getFilename()))->extensionWithDelimiter());
        try {
            if (!@copy($source, $copy)) {
                throw new FileNotFound($blob->getKey());
            }
            if (null !== $blob->getChecksum() && DiskService::checksumFile($copy) !== $blob->getChecksum()) {
                throw new IntegrityError(\sprintf('Checksum mismatch for blob %d.', $blob->getId()));
            }

            return $work($copy);
        } finally {
            @unlink($copy);
        }
    }

    /** `Blob#variable?` */
    public static function isVariable(Blob $blob): bool
    {
        return ContentTypes::isVariable($blob->getContentType());
    }

    /** `Blob#previewable?`: videos, when ffmpeg is installed. */
    public static function isPreviewable(Blob $blob): bool
    {
        return $blob->isVideo() && Processing\VideoPreviewer::ffmpegExists();
    }

    /** `Blob#representable?` */
    public static function isRepresentable(Blob $blob): bool
    {
        return self::isVariable($blob) || self::isPreviewable($blob);
    }

    /**
     * `default_variant_format`: web images keep their format (a String, from the filename or the
     * type's first extension), anything else becomes `:png`.
     */
    public static function defaultVariantFormat(Blob $blob): string|Symbol
    {
        if (!ContentTypes::isWebImage($blob->getContentType())) {
            return new Symbol('png');
        }
        $extension = (new Filename($blob->getFilename()))->extension();
        if ('' !== $extension && Marcel::forExtension($extension) === $blob->getContentType()) {
            return $extension;
        }

        return Marcel::extensions((string) $blob->getContentType())[0] ?? new Symbol('png');
    }

    /** `Blob.generate_unique_secure_token(length: 28)`: `SecureRandom.base36(28)`. */
    public static function generateKey(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $key = '';
        for ($i = 0; $i < self::KEY_LENGTH; ++$i) {
            $key .= $alphabet[random_int(0, 35)];
        }

        return $key;
    }

    /**
     * `metadata.merge(extracted)`, written back with ActiveSupport's JSON encoding.
     *
     * @param array<string, mixed> $extracted
     */
    public static function mergeMetadata(?string $metadataJson, array $extracted): string
    {
        $metadata = null === $metadataJson || '' === $metadataJson ? [] : RailsJson::decode($metadataJson);
        if (!\is_array($metadata) || array_is_list($metadata) && [] !== $metadata) {
            $metadata = [];
        }
        foreach ($extracted as $key => $value) {
            $metadata[$key] = $value;
        }

        return [] === $metadata ? '{}' : RailsJson::encode($metadata);
    }

    /** `touch_attachments`: each attachment's record (and what it touches in turn). */
    public function touchAttachments(Blob $blob): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT record_type, record_id FROM active_storage_attachments WHERE blob_id = ? ORDER BY id', [$blob->getId()]);
        foreach ($rows as $row) {
            $this->toucher->touch((string) $row['record_type'], (int) $row['record_id']);
        }
    }

    /**
     * `unfurl`: `extract_content_type` unless a type was declared and `identify` is false.
     */
    private function identify(string $head, string $filename, ?string $declaredType, bool $identify): string
    {
        if (null === $declaredType || $identify) {
            return Marcel::identify($head, (new Filename($filename))->sanitized(), $declaredType);
        }

        return $declaredType;
    }

    /**
     * Uploads the bytes, then saves the row (Rails saves first and uploads in an after_save; a
     * row without a file would be worse than a file without a row, which is deleted on failure).
     *
     * @param resource|string $source
     */
    private function create(Filename $filename, ?string $contentType, int $byteSize, string $checksum, mixed $source): Blob
    {
        $key = self::generateKey();
        $this->disk->upload($key, $source);
        try {
            $blob = $this->transactions->transaction(function () use ($key, $filename, $contentType, $byteSize, $checksum): Blob {
                $this->connection->insert('active_storage_blobs', [
                    'key' => $key,
                    'filename' => $filename->raw,
                    'content_type' => $contentType,
                    'metadata' => '{"identified":true}',
                    'service_name' => DiskService::NAME,
                    'byte_size' => $byteSize,
                    'checksum' => $checksum,
                    'created_at' => RailsDateTimeType::format($this->clock->now()),
                ]);

                return $this->em->find(Blob::class, (int) $this->connection->lastInsertId()) ?? throw new \LogicException('The new blob vanished.');
            });

            return $blob;
        } catch (\Throwable $e) {
            $this->disk->delete($key);
            throw $e;
        }
    }
}
