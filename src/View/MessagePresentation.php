<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Messages\Sound;
use App\Entity\ActiveStorage\Attachment;
use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\User;

/**
 * What `messages/_message` reads of a message beyond its own columns, loaded in batches by
 * MessagePresentationLoader (the `with_presentation` preload: creator, rich text body,
 * attachment and blob, boosts and boosters, room).
 */
final readonly class MessagePresentation
{
    /** @param list<Boost> $boosts `message.boosts.ordered` */
    public function __construct(
        public Message $message,
        public ?User $creator,
        public ?string $body,
        public ?Attachment $attachment,
        public array $boosts,
        public ?string $roomName,
        public string $plainText,
        public ?Sound $sound,
        public bool $failed = false,
    ) {
    }

    /** `message.content_type`: "attachment", "sound" or "text". */
    public function contentType(): string
    {
        return match (true) {
            null !== $this->attachment => 'attachment',
            null !== $this->sound => 'sound',
            default => 'text',
        };
    }

    /** `message.plain_text_body.all_emoji?` */
    public function isAllEmoji(): bool
    {
        return self::allEmoji($this->plainText);
    }

    /** String#all_emoji? (reference/lib/rails_ext/string.rb) */
    public static function allEmoji(string $text): bool
    {
        return 1 === preg_match('/\A(\p{Emoji_Presentation}|\p{Extended_Pictographic}|\x{FE0F})+\z/u', $text);
    }

    /** `message_tag` rescues whatever rendering raises, a creator that is gone included. */
    public function isRenderable(): bool
    {
        return null !== $this->creator && !$this->failed;
    }
}
