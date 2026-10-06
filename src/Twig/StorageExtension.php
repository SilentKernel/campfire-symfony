<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ActiveStorage\Attachment;
use App\Entity\ActiveStorage\Blob;
use App\Entity\Message;
use App\Rails\RubyFloat;
use App\Storage\Attachments;
use App\Storage\BlobService;
use App\Storage\Filename;
use App\Storage\NamedVariants;
use App\Storage\StorageUrls;
use App\Twig\Html\Tag;
use App\Twig\View\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Active Storage in views: Messages::AttachmentPresentation
 * (reference/app/helpers/messages/attachment_presentation.rb, the "attachment" branch of
 * MessagesHelper#message_presentation) and the route helpers rails_blob_path,
 * rails_storage_proxy_path and url_for(representation/variant/preview).
 */
final class StorageExtension extends AbstractExtension
{
    public function __construct(
        private readonly StorageUrls $urls,
        private readonly Attachments $attachments,
        private readonly ViewHelpers $helpers,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('message_attachment_presentation', $this->messageAttachmentPresentation(...), $safe),
            new TwigFunction('rails_blob_path', $this->urls->blobPath(...)),
            new TwigFunction('rails_storage_proxy_path', $this->urls->blobProxyPath(...)),
            new TwigFunction('rails_representation_path', $this->urls->representationPath(...)),
            new TwigFunction('rails_variant_path', $this->urls->variantPath(...)),
            new TwigFunction('rails_preview_path', $this->urls->previewPath(...)),
        ];
    }

    /**
     * `Messages::AttachmentPresentation.new(message, context: self).render`: '' without an
     * attachment. Pass the attachment when it is already loaded (Attachments::forRecords) to skip
     * the lookup.
     */
    public function messageAttachmentPresentation(Message|int $message, ?Attachment $attachment = null): string
    {
        $attachment ??= $this->attachments->find(Message::RECORD_TYPE, $message instanceof Message ? $message->getId() : $message, 'attachment');
        if (null === $attachment) {
            return '';
        }
        $blob = $attachment->getBlob();

        if (BlobService::isPreviewable($blob) || BlobService::isVariable($blob)) {
            return $blob->isVideo() ? $this->videoPreviewTag($blob) : $this->lightboxedImagePreviewTag($blob);
        }

        return $this->renderLink($blob);
    }

    /**
     * `preview_dimensions`: the blob's metadata dimensions, scaled down to fit 1200x800.
     *
     * @return array{int|float|null, int|float|null}
     */
    public static function previewDimensions(Blob $blob): array
    {
        $metadata = $blob->getMetadata();
        $width = $metadata['width'] ?? null;
        $height = $metadata['height'] ?? null;
        if (!\is_int($width) && !\is_float($width) || !\is_int($height) && !\is_float($height)) {
            return [null, null];
        }
        if ($width <= NamedVariants::THUMBNAIL_MAX_WIDTH && $height <= NamedVariants::THUMBNAIL_MAX_HEIGHT) {
            return [$width, $height];
        }
        $scale = min((float) NamedVariants::THUMBNAIL_MAX_WIDTH / $width, (float) NamedVariants::THUMBNAIL_MAX_HEIGHT / $height);

        return [$width * $scale, $height * $scale];
    }

    private function videoPreviewTag(Blob $blob): string
    {
        [$width, $height] = self::previewDimensions($blob);
        $video = Tag::content('video', null, [
            'src' => $this->urls->blobPath($blob),
            'poster' => $this->urls->previewPath($blob, NamedVariants::poster()),
            'controls' => true,
            'preload' => 'none',
            'width' => '100%',
            'height' => '100%',
            'class' => 'message__attachment',
        ]);

        return self::inlineMediaDimensionConstraints($width, $height, $video);
    }

    private function lightboxedImagePreviewTag(Blob $blob): string
    {
        [$width, $height] = self::previewDimensions($blob);
        $image = $this->helpers->imageTag($this->urls->representationPath($blob, 'thumb'), [
            'width' => self::rubyNumber($width),
            'height' => self::rubyNumber($height),
            'class' => 'message__attachment',
            'loading' => 'lazy',
        ]);
        $link = $this->helpers->linkTo(new Markup($image, 'UTF-8'), $this->urls->blobPath($blob), [
            'class' => 'flex',
            'data' => ['lightbox_target' => 'image', 'action' => 'lightbox#open', 'lightbox_url_value' => $this->downloadUrl($blob)],
        ]);

        return self::inlineMediaDimensionConstraints($width, $height, $link);
    }

    private static function inlineMediaDimensionConstraints(int|float|null $width, int|float|null $height, string $content): string
    {
        if (null !== $width && null !== $height) {
            $aspectRatio = $width / (float) $height;
            $half = \is_int($width) ? (int) floor($width / 2) : $width / 2;

            return Tag::content('div', new Markup($content, 'UTF-8'), [
                'class' => 'max-inline-size center flex overflow-clip',
                'style' => \sprintf('width: %spx; aspect-ratio: %s;', self::rubyNumber($half), RubyFloat::toS($aspectRatio)),
            ]);
        }

        return Tag::content('div', new Markup($content, 'UTF-8'), ['class' => 'max-inline-size center overflow-clip']);
    }

    private function renderLink(Blob $blob): string
    {
        $filename = (new Filename($blob->getFilename()))->sanitized();
        $icon = $this->helpers->imageTag('common-file-text.svg', ['size' => 22, 'class' => 'colorize--black', 'aria' => ['hidden' => 'true']]);
        $download = $this->helpers->linkTo(
            new Markup($this->helpers->imageTag('download.svg', ['aria' => ['hidden' => 'true'], 'size' => 20]).Tag::content('span', 'Download '.$filename, ['class' => 'for-screen-reader']), 'UTF-8'),
            $this->downloadUrl($blob),
            ['class' => 'btn message__action-btn hide-in-ios-pwa', 'style' => '--width: auto;'],
        );
        $share = Tag::content('button', new Markup($this->helpers->imageTag('share.svg', ['aria' => ['hidden' => 'true'], 'size' => 20]).Tag::content('span', 'Share '.$filename, ['class' => 'for-screen-reader']), 'UTF-8'), [
            'class' => 'btn message__action-btn',
            'style' => '--width: auto;',
            'data' => ['controller' => 'web-share', 'action' => 'web-share#share', 'web_share_files_value' => $this->downloadUrl($blob)],
        ]);

        return Tag::content('div', new Markup($icon.Tag::content('span', $filename).$download.$share, 'UTF-8'), ['class' => 'flex-inline align-center gap-half']);
    }

    /** `rails_blob_path attachment, disposition: "attachment", only_path: true` */
    private function downloadUrl(Blob $blob): string
    {
        return $this->urls->blobPath($blob, 'attachment');
    }

    /** Integer#to_s / Float#to_s, null kept (image_tag then drops the attribute). */
    private static function rubyNumber(int|float|null $value): ?string
    {
        return match (true) {
            null === $value => null,
            \is_float($value) => RubyFloat::toS($value),
            default => (string) $value,
        };
    }
}
