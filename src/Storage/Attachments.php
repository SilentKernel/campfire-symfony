<?php

declare(strict_types=1);

namespace App\Storage;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\ActiveStorage\Attachment;
use App\Entity\ActiveStorage\Blob;
use App\Repository\ActiveStorage\AttachmentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * `has_one_attached` (every Campfire attachment is one: Message#attachment, User#avatar,
 * Account#logo, plus Active Storage's own VariantRecord#image and Blob#preview_image), with
 * ActiveStorage::Attachment's callbacks: the record is touched on create and destroy, a new
 * attachment's blob is analyzed later, a replaced or destroyed attachment's blob is purged later
 * (`dependent: :purge_later`).
 */
#[Autoconfigure(public: true)]
final readonly class Attachments
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private AttachmentRepository $repository,
        private BlobService $blobs,
        private RecordToucher $toucher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `record.<name>.attach(blob)` / `record.update!(name => blob)`: replaces the record's current
     * attachment of that name.
     */
    public function attach(string $recordType, int $recordId, string $name, Blob $blob): Attachment
    {
        return $this->transactions->transaction(function () use ($recordType, $recordId, $name, $blob): Attachment {
            $existing = $this->find($recordType, $recordId, $name);
            if (null !== $existing && $existing->getBlob()->getId() === $blob->getId()) {
                return $existing;
            }
            if (null !== $existing) {
                $this->destroyAttachment($existing, purgeBlobLater: true);
            }
            $attachment = $this->insert($recordType, $recordId, $name, $blob);
            $this->toucher->touch($recordType, $recordId);
            $this->transactions->afterCommit(function () use ($blob): void {
                // after_create_commit :analyze_blob_later
                if (!$this->blobs->isAnalyzed($blob)) {
                    $this->blobs->analyzeLater($blob);
                }
            });

            return $attachment;
        });
    }

    /** `record.<name>_attachment`, blob loaded. */
    public function find(string $recordType, int $recordId, string $name): ?Attachment
    {
        return $this->repository->findOneFor($recordType, $recordId, $name);
    }

    /**
     * The `<name>_attachment` of several records of one type (`with_attached_<name>`), keyed by
     * record id, blobs preloaded.
     *
     * @param list<int> $ids
     *
     * @return array<int, Attachment>
     */
    public function forRecords(string $recordType, array $ids, string $name): array
    {
        return $this->repository->findForRecords($recordType, $ids, $name);
    }

    /**
     * `record.<name>.destroy` (Attached::One#destroy): the attachment row goes now, the record is
     * touched, and the blob is purged later.
     */
    public function detach(string $recordType, int $recordId, string $name): void
    {
        $this->transactions->transaction(function () use ($recordType, $recordId, $name): void {
            $attachment = $this->find($recordType, $recordId, $name);
            if (null !== $attachment) {
                $this->destroyAttachment($attachment, purgeBlobLater: true);
            }
        });
    }

    /** `record.<name>.purge`: like detach, but the blob and its files go right away. */
    public function purge(string $recordType, int $recordId, string $name): void
    {
        $attachment = $this->find($recordType, $recordId, $name);
        if (null === $attachment) {
            return;
        }
        $blob = $attachment->getBlob();
        $this->transactions->transaction(fn () => $this->destroyAttachment($attachment, purgeBlobLater: false));
        $this->blobs->purge($blob);
    }

    /** Inserts the row without callbacks (used for variant images and preview images too). */
    public function insert(string $recordType, int $recordId, string $name, Blob $blob): Attachment
    {
        $this->connection->insert('active_storage_attachments', [
            'name' => $name,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'blob_id' => $blob->getId(),
            'created_at' => RailsDateTimeType::format($this->clock->now()),
        ]);

        return $this->em->find(Attachment::class, (int) $this->connection->lastInsertId()) ?? throw new \LogicException('The new attachment vanished.');
    }

    private function destroyAttachment(Attachment $attachment, bool $purgeBlobLater): void
    {
        $this->connection->delete('active_storage_attachments', ['id' => $attachment->getId()]);
        $this->toucher->touch($attachment->getRecordType(), $attachment->getRecordId());
        $blob = $attachment->getBlob();
        $this->em->detach($attachment);
        if ($purgeBlobLater) {
            // after_destroy_commit :purge_dependent_blob_later
            $this->transactions->afterCommit(fn () => $this->blobs->purgeLater($blob));
        }
    }
}
