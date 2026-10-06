<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The effective `config.active_storage` lists: Rails 8.2 engine defaults with `load_defaults 8.2`,
 * minus the types removed in reference/config/initializers/vips.rb.
 */
final class ContentTypes
{
    /** `variable_content_types` (bmp, ico and psd removed by config/initializers/vips.rb). */
    public const array VARIABLE = ['image/png', 'image/gif', 'image/jpeg', 'image/tiff', 'image/webp', 'image/avif', 'image/heic', 'image/heif'];

    /** `web_image_content_types` */
    public const array WEB_IMAGE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** `content_types_allowed_inline` */
    public const array ALLOWED_INLINE = [
        'image/webp', 'image/avif', 'image/png', 'image/gif', 'image/jpeg', 'image/tiff', 'image/bmp',
        'image/vnd.adobe.photoshop', 'image/vnd.microsoft.icon', 'application/pdf',
    ];

    /** `content_types_to_serve_as_binary` */
    public const array SERVE_AS_BINARY = [
        'text/html', 'image/svg+xml', 'application/postscript', 'application/x-shockwave-flash', 'text/xml',
        'application/xml', 'application/xhtml+xml', 'application/mathml+xml', 'text/cache-manifest',
    ];

    public const string BINARY = 'application/octet-stream';

    /** `ActiveStorage.video_preview_arguments` (load_defaults 7.0), already shell-split. */
    public const array VIDEO_PREVIEW_ARGUMENTS = [
        '-vf', 'select=eq(n\,0)+eq(key\,1)+gt(scene\,0.015),loop=loop=-1:size=2,trim=start_frame=1', '-frames:v', '1', '-f', 'image2',
    ];

    public static function isVariable(?string $contentType): bool
    {
        return \in_array($contentType, self::VARIABLE, true);
    }

    public static function isWebImage(?string $contentType): bool
    {
        return \in_array($contentType, self::WEB_IMAGE, true);
    }

    /** `Blob#content_type_for_serving` */
    public static function forServing(?string $contentType): ?string
    {
        return \in_array($contentType, self::SERVE_AS_BINARY, true) ? self::BINARY : $contentType;
    }

    /** `Blob#forced_disposition_for_serving`: "attachment" for binary or non-inline types. */
    public static function forcedDisposition(?string $contentType): ?string
    {
        return \in_array($contentType, self::SERVE_AS_BINARY, true) || !\in_array($contentType, self::ALLOWED_INLINE, true) ? 'attachment' : null;
    }
}
