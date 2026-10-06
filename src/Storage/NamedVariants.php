<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The `has_one_attached ... do |attachable| attachable.variant ...` declarations:
 * reference/app/models/message/attachment.rb (:thumb), user/avatar.rb (:square) and account.rb
 * (:large, :small).
 */
final class NamedVariants
{
    /** `Message::THUMBNAIL_MAX_WIDTH`, `THUMBNAIL_MAX_HEIGHT` */
    public const int THUMBNAIL_MAX_WIDTH = 1200;
    public const int THUMBNAIL_MAX_HEIGHT = 800;

    public static function get(string $name): Variation
    {
        return match ($name) {
            'thumb' => Variation::resizeToLimit(self::THUMBNAIL_MAX_WIDTH, self::THUMBNAIL_MAX_HEIGHT),
            'square' => Variation::resizeToLimit(512, 512, 'webp'),
            'large' => Variation::resizeToLimit(512, 512, 'png'),
            'small' => Variation::resizeToLimit(192, 192, 'png'),
            default => throw new \InvalidArgumentException(\sprintf('Cannot find variant :%s', $name)),
        };
    }

    /** `preview(format: :webp, resize_to_limit: [1200, 800])`, the video poster of a message. */
    public static function poster(): Variation
    {
        return new Variation(['format' => new Symbol('webp'), 'resize_to_limit' => [self::THUMBNAIL_MAX_WIDTH, self::THUMBNAIL_MAX_HEIGHT]]);
    }

    /** `preview(format: :webp)`, which Message#process_attachment processes for videos. */
    public static function webpPreview(): Variation
    {
        return new Variation(['format' => new Symbol('webp')]);
    }
}
