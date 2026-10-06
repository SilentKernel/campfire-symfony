<?php

declare(strict_types=1);

namespace App\Storage\Http;

use App\Entity\ActiveStorage\Blob;
use App\Http\Exception\RecordNotFound;
use App\Repository\ActiveStorage\BlobRepository;
use App\Storage\StorageUrls;
use Symfony\Component\Clock\ClockInterface;

/**
 * `ActiveStorage::SetBlob#set_blob`: `Blob.find_signed!(params[:signed_blob_id] || params[:signed_id])`.
 * A bad signature yields null (the controller answers `head :not_found`); a valid one for a
 * missing blob raises RecordNotFound (the 404 page).
 */
final readonly class BlobLookup
{
    public function __construct(
        private StorageUrls $urls,
        private BlobRepository $blobs,
        private ClockInterface $clock,
    ) {
    }

    public function findSigned(mixed $signedId): ?Blob
    {
        if (!\is_string($signedId) || '' === $signedId) {
            return null;
        }
        $id = $this->urls->verifySignedId($signedId, $this->clock->now());
        if (null === $id) {
            return null;
        }

        return $this->blobs->find($id) ?? throw RecordNotFound::for('ActiveStorage::Blob', $id);
    }
}
