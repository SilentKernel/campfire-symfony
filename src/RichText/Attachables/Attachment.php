<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * `ActionText::Attachment`: the resolved attachable, and the caption its node carries.
 */
final readonly class Attachment
{
    public function __construct(
        public MentionUser|OpengraphEmbed|ContentAttachment|RemoteImage|RemoteVideo|MissingAttachable $attachable,
        public ?string $caption,
    ) {
    }
}
