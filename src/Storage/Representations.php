<?php

declare(strict_types=1);

namespace App\Storage;

use App\Database\Transactions;
use App\Entity\ActiveStorage\Blob;
use App\Entity\ActiveStorage\VariantRecord;
use App\Storage\Processing\VideoPreviewer;
use App\Storage\Processing\Vips;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Tracked variants (`ActiveStorage::VariantWithRecord`, `track_variants` is on with load_defaults)
 * and video previews (`ActiveStorage::Preview`): `blob.variant(t).processed`,
 * `blob.preview(t).processed` and `blob.representation(t).processed`, each returning the blob
 * that holds the processed image.
 *
 * The slow work (libvips, ffmpeg) runs outside any transaction; the rows are written afterwards,
 * `create_or_find_by!` style, so a concurrent request that recorded the same variant first wins.
 */
#[Autoconfigure(public: true)]
final readonly class Representations
{
    public function __construct(
        private Connection $connection,
        private Transactions $transactions,
        private BlobService $blobs,
        private Attachments $attachments,
    ) {
    }

    /**
     * `blob.variant(transformations)`'s variation: `Variation.wrap(t).default_to(format:
     * default_variant_format)`.
     *
     * @throws InvariableError
     */
    public function variation(Blob $blob, Variation $transformations): Variation
    {
        if (!BlobService::isVariable($blob)) {
            throw new InvariableError(\sprintf("Can't transform blob with ID=%d and content_type=%s", $blob->getId(), $blob->getContentType()));
        }

        return $transformations->defaultTo(['format' => BlobService::defaultVariantFormat($blob)]);
    }

    /**
     * The processed variant's image blob when its variant record exists, without processing.
     *
     * @phpstan-impure
     */
    public function existingVariant(Blob $blob, Variation $variation): ?Blob
    {
        $recordId = $this->connection->fetchOne(
            'SELECT id FROM active_storage_variant_records WHERE blob_id = ? AND variation_digest = ?',
            [$blob->getId(), $variation->digest()],
        );
        if (false === $recordId) {
            return null;
        }

        return $this->attachments->find(VariantRecord::RECORD_TYPE, (int) $recordId, 'image')?->getBlob();
    }

    /**
     * `blob.variant(transformations).processed.image.blob` for an already defaulted variation.
     *
     * @throws InvalidVariation|ProcessingError|FileNotFound|IntegrityError
     */
    public function processedVariant(Blob $blob, Variation $variation): Blob
    {
        if (null !== $existing = $this->existingVariant($blob, $variation)) {
            return $existing;
        }

        $format = $variation->format();
        $output = $this->blobs->withLocalFile($blob, static fn (string $input): string => Vips::transform($input, $variation));
        try {
            $filename = (new Filename($blob->getFilename()))->base().'.'.strtolower($format);
            $image = $this->blobs->createFromFile($output, $filename, $variation->contentType());
        } finally {
            @unlink($output);
        }

        $recorded = $this->transactions->transaction(function () use ($blob, $variation, $image): bool {
            try {
                $this->connection->insert('active_storage_variant_records', ['blob_id' => $blob->getId(), 'variation_digest' => $variation->digest()]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
            $this->attachments->attach(VariantRecord::RECORD_TYPE, (int) $this->connection->lastInsertId(), 'image', $image);

            return true;
        });
        if (!$recorded) {
            $this->blobs->purge($image);

            return $this->existingVariant($blob, $variation) ?? throw new FileNotFound('variant');
        }

        return $image;
    }

    /**
     * `blob.preview_image`, when it has been generated.
     *
     * @phpstan-impure
     */
    public function existingPreviewImage(Blob $blob): ?Blob
    {
        return $this->attachments->find(Blob::RECORD_TYPE, $blob->getId(), 'preview_image')?->getBlob();
    }

    /**
     * `Preview#process`: the video's frame drawn by ffmpeg, attached as the blob's
     * `preview_image` (`<base>.jpg`, image/jpeg).
     *
     * @throws UnpreviewableError|ProcessingError|FileNotFound|IntegrityError
     */
    public function previewImage(Blob $blob): Blob
    {
        if (null !== $existing = $this->existingPreviewImage($blob)) {
            return $existing;
        }
        if (!BlobService::isPreviewable($blob)) {
            throw new UnpreviewableError(\sprintf('No previewer found for blob with ID=%d and content_type=%s', $blob->getId(), $blob->getContentType()));
        }

        $frame = $this->blobs->withLocalFile($blob, VideoPreviewer::drawFrame(...));
        $image = $this->blobs->createFromBytes($frame, (new Filename($blob->getFilename()))->base().'.jpg', 'image/jpeg');

        $recorded = $this->transactions->transaction(function () use ($blob, $image): bool {
            if (null !== $this->existingPreviewImage($blob)) {
                return false;
            }
            $this->attachments->attach(Blob::RECORD_TYPE, $blob->getId(), 'preview_image', $image);

            return true;
        });
        if (!$recorded) {
            $this->blobs->purge($image);

            return $this->existingPreviewImage($blob) ?? throw new FileNotFound('preview image');
        }

        return $image;
    }

    /**
     * `blob.preview(transformations).processed`, returning the blob to serve: the preview image
     * itself without transformations, else its processed variant.
     */
    public function processedPreview(Blob $blob, Variation $transformations): Blob
    {
        $image = $this->previewImage($blob);
        if ($transformations->isEmpty()) {
            return $image;
        }

        return $this->processedVariant($image, $this->variation($image, $transformations));
    }

    /**
     * `blob.representation(transformations).processed`: a preview for previewable blobs, a variant
     * for variable ones.
     *
     * @throws UnrepresentableError|InvalidVariation|ProcessingError|FileNotFound|IntegrityError
     */
    public function processedRepresentation(Blob $blob, Variation $transformations): Blob
    {
        if (BlobService::isPreviewable($blob)) {
            return $this->processedPreview($blob, $transformations);
        }
        if (BlobService::isVariable($blob)) {
            return $this->processedVariant($blob, $this->variation($blob, $transformations));
        }

        throw new UnrepresentableError(\sprintf("No previewer found and can't transform blob with ID=%d and content_type=%s", $blob->getId(), $blob->getContentType()));
    }

    /** `record.<attachment>.variant(name).processed if <attachment>.variable?` (avatars, logos). */
    public function processedNamedVariant(string $recordType, int $recordId, string $attachmentName, string $variantName): ?Blob
    {
        $blob = $this->attachments->find($recordType, $recordId, $attachmentName)?->getBlob();
        if (null === $blob || !BlobService::isVariable($blob)) {
            return null;
        }

        return $this->processedVariant($blob, $this->variation($blob, NamedVariants::get($variantName)));
    }
}
