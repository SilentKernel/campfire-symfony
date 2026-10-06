<?php

declare(strict_types=1);

namespace App\Storage\Http;

use App\Entity\ActiveStorage\Blob;
use App\Storage\DiskService;
use App\Storage\Representations;
use App\Storage\Variation;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * ActiveStorage::Representations::BaseController's before_actions: set_blob, then
 * `@representation = @blob.representation(params[:variation_key]).processed` (a bad signature on
 * either is `head :not_found`). Returns the blob holding the processed image, or null for a 404.
 */
final readonly class RepresentationLookup
{
    public function __construct(
        private BlobLookup $blobs,
        private Representations $representations,
        private DiskService $disk,
        private ClockInterface $clock,
    ) {
    }

    public function processed(Request $request): ?Blob
    {
        $blob = $this->blobs->findSigned($request->attributes->get('signed_blob_id'));
        $key = $request->attributes->get('variation_key');
        if (null === $blob || !\is_string($key)) {
            return null;
        }
        $variation = Variation::decode($this->disk->verifier, $key, $this->clock->now());
        if (null === $variation) {
            return null;
        }

        return $this->representations->processedRepresentation($blob, $variation);
    }
}
