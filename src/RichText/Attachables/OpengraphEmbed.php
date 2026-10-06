<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * `ActionText::Attachment::OpengraphEmbed` (reference/lib/rails_ext/actiontext_opengraph_embeds.rb).
 */
final readonly class OpengraphEmbed
{
    public const string CONTENT_TYPE = 'application/vnd.actiontext.opengraph-embed';
    public const string TWITTER_AVATAR_URL_PREFIX = 'https://pbs.twimg.com/profile_images';

    public function __construct(
        public ?string $href,
        public ?string $url,
        public ?string $filename,
        public ?string $description,
    ) {
    }

    public function isTwitterAvatar(): bool
    {
        return str_starts_with($this->url ?? '', self::TWITTER_AVATAR_URL_PREFIX);
    }
}
