<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Why a signed or encrypted message could not be read, mirroring what ActiveSupport::Messages
 * throws: :invalid_message_format (bad encoding or signature), :invalid_message_serialization
 * (authentic but undeserializable) and :invalid_message_content (expired or wrong purpose).
 * Rotations (MessageVerifier::withFallback) are only tried after the first two.
 */
final class InvalidMessage extends \RuntimeException
{
    public const int FORMAT = 1;
    public const int SERIALIZATION = 2;
    public const int CONTENT = 3;

    public static function format(string $reason): self
    {
        return new self($reason, self::FORMAT);
    }

    public static function serialization(string $reason): self
    {
        return new self($reason, self::SERIALIZATION);
    }

    public static function content(string $reason): self
    {
        return new self($reason, self::CONTENT);
    }

    public function rotates(): bool
    {
        return self::CONTENT !== $this->getCode();
    }
}
