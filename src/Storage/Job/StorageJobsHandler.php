<?php

declare(strict_types=1);

namespace App\Storage\Job;

use App\Entity\ActiveStorage\Blob;
use App\Storage\BlobService;
use App\Storage\FileNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * ActiveStorage::AnalyzeJob (`blob.analyze`, discarded when the blob is gone or its file missing)
 * and ActiveStorage::PurgeJob (`blob.purge`, discarded when the blob is gone).
 */
final readonly class StorageJobsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private BlobService $blobs,
    ) {
    }

    #[AsMessageHandler]
    public function analyze(AnalyzeBlob $job): void
    {
        $blob = $this->em->find(Blob::class, $job->blobId);
        if (null === $blob) {
            return;
        }
        try {
            $this->blobs->analyze($blob);
        } catch (FileNotFound) {
            // discard_on ActiveStorage::FileNotFoundError
        }
    }

    #[AsMessageHandler]
    public function purge(PurgeBlob $job): void
    {
        $blob = $this->em->find(Blob::class, $job->blobId);
        if (null !== $blob) {
            $this->blobs->purge($blob);
        }
    }
}
