<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\ActiveStorage\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * `Message#process_attachment` (reference/app/models/message/attachment.rb), run synchronously
 * right after `create!` as `create_with_attachment!` does: analyze the blob, then process the
 * video's webp preview or the image's :thumb variant.
 */
#[Autoconfigure(public: true)]
final readonly class MessageAttachmentProcessor
{
    public function __construct(
        private BlobService $blobs,
        private Representations $representations,
    ) {
    }

    public function process(Attachment $attachment): void
    {
        $blob = $attachment->getBlob();
        // ensure_attachment_analyzed
        $this->blobs->analyze($blob);

        // process_attachment_thumbnail
        if ($blob->isVideo()) {
            // attachment.preview(format: :webp).processed: raises UnpreviewableError without ffmpeg,
            // exactly as Rails does.
            $this->representations->processedPreview($blob, NamedVariants::webpPreview());
        } elseif (BlobService::isRepresentable($blob)) {
            $this->representations->processedRepresentation($blob, NamedVariants::get('thumb'));
        }
    }
}
