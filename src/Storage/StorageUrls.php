<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\ActiveStorage\Attachment;
use App\Entity\ActiveStorage\Blob;
use App\Rails\MessageVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Active Storage's route helpers (activestorage/config/routes.rb) with
 * `resolve_model_to_route = :rails_storage_redirect`: `rails_blob_path`, `url_for(variant)`,
 * `url_for(preview)`, `rails_storage_proxy_path`. `urls_expire_in` is unset in Campfire, so the
 * signed ids never expire and the paths are deterministic.
 */
#[Autoconfigure(public: true)]
final readonly class StorageUrls
{
    public const string PREFIX = '/rails/active_storage';

    private MessageVerifier $verifier;

    public function __construct(DiskService $disk)
    {
        $this->verifier = $disk->verifier;
    }

    /** `blob.signed_id`: the ActiveStorage verifier, purpose "blob_id" (not Active Record's scheme). */
    public function signedId(Blob|int $blob): string
    {
        return $this->verifier->generate($blob instanceof Blob ? $blob->getId() : $blob, 'blob_id');
    }

    /** `ActiveStorage::Blob.find_signed`'s verification: the blob id, or null. */
    public function verifySignedId(string $signedId, ?\DateTimeInterface $now = null): ?int
    {
        $id = $this->verifier->verified($signedId, 'blob_id', $now);
        if (\is_string($id) && 1 === preg_match('/\A\d+\z/', $id)) {
            $id = (int) $id;
        }

        return \is_int($id) ? $id : null;
    }

    /** `rails_blob_path(blob_or_attachment, disposition:)`: `/rails/active_storage/blobs/redirect/:signed_id/*filename`. */
    public function blobPath(Blob|Attachment $blob, ?string $disposition = null): string
    {
        return $this->blobRoute('redirect', $blob instanceof Attachment ? $blob->getBlob() : $blob, $disposition);
    }

    /** `rails_storage_proxy_path(blob)`: `/rails/active_storage/blobs/proxy/:signed_id/*filename`. */
    public function blobProxyPath(Blob|Attachment $blob, ?string $disposition = null): string
    {
        return $this->blobRoute('proxy', $blob instanceof Attachment ? $blob->getBlob() : $blob, $disposition);
    }

    /**
     * `url_for(blob.representation(variation))`: a preview's transformations as given, a variant's
     * defaulted to the blob's variant format. `$variation` is a named variant ("thumb"), a
     * transformations hash or a Variation.
     *
     * @param array<string, mixed>|string|Variation $variation
     */
    public function representationPath(Blob|Attachment $blob, array|string|Variation $variation): string
    {
        $blob = $blob instanceof Attachment ? $blob->getBlob() : $blob;
        $variation = self::wrap($variation);
        if (BlobService::isPreviewable($blob)) {
            return $this->representationRoute('redirect', $blob, $variation);
        }
        if (BlobService::isVariable($blob)) {
            return $this->representationRoute('redirect', $blob, $variation->defaultTo(['format' => BlobService::defaultVariantFormat($blob)]));
        }

        throw new UnrepresentableError(\sprintf("No previewer found and can't transform blob with ID=%d and content_type=%s", $blob->getId(), $blob->getContentType()));
    }

    /**
     * `url_for(blob.variant(variation))` for a named variant (thumb, square, large, small) or any
     * transformations.
     *
     * @param array<string, mixed>|string|Variation $variation
     */
    public function variantPath(Blob|Attachment $blob, array|string|Variation $variation): string
    {
        $blob = $blob instanceof Attachment ? $blob->getBlob() : $blob;

        return $this->representationRoute('redirect', $blob, self::wrap($variation)->defaultTo(['format' => BlobService::defaultVariantFormat($blob)]));
    }

    /**
     * `url_for(blob.preview(variation))`, by default the message video poster
     * (`preview(format: :webp, resize_to_limit: [1200, 800])`).
     *
     * @param array<string, mixed>|Variation|null $variation
     */
    public function previewPath(Blob|Attachment $blob, array|Variation|null $variation = null): string
    {
        $blob = $blob instanceof Attachment ? $blob->getBlob() : $blob;

        return $this->representationRoute('redirect', $blob, null === $variation ? NamedVariants::poster() : self::wrap($variation));
    }

    /**
     * `rails_storage_proxy_path(representation)` for an already resolved variation (defaulted
     * for variants, as given for previews).
     */
    public function representationProxyPath(Blob $blob, Variation $variation): string
    {
        return $this->representationRoute('proxy', $blob, $variation);
    }

    /** The redirect form for an already resolved variation. */
    public function representationRedirectPath(Blob $blob, Variation $variation): string
    {
        return $this->representationRoute('redirect', $blob, $variation);
    }

    /** @param array<string, mixed>|string|Variation $variation */
    private static function wrap(array|string|Variation $variation): Variation
    {
        return match (true) {
            $variation instanceof Variation => $variation,
            \is_string($variation) => NamedVariants::get($variation),
            default => new Variation($variation),
        };
    }

    private function blobRoute(string $kind, Blob $blob, ?string $disposition): string
    {
        $path = \sprintf(
            '%s/blobs/%s/%s/%s',
            self::PREFIX,
            $kind,
            Paths::escapeSegment($this->signedId($blob)),
            Paths::escapePath((new Filename($blob->getFilename()))->sanitized()),
        );

        return null === $disposition ? $path : $path.'?disposition='.Paths::cgiEscape($disposition);
    }

    private function representationRoute(string $kind, Blob $blob, Variation $variation): string
    {
        return \sprintf(
            '%s/representations/%s/%s/%s/%s',
            self::PREFIX,
            $kind,
            Paths::escapeSegment($this->signedId($blob)),
            Paths::escapeSegment($variation->key($this->verifier)),
            Paths::escapePath((new Filename($blob->getFilename()))->sanitized()),
        );
    }
}
