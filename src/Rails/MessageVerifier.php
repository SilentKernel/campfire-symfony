<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * ActiveSupport::MessageVerifier: "<base64 payload>--<hex HMAC of the base64 payload>"
 * (activesupport/lib/active_support/message_verifier.rb).
 *
 * Generation encodes with strict Base64, or URL-safe Base64 without padding ($urlSafe), or URL-safe
 * with padding ($paddedUrlSafe: GlobalID::Verifier). Reading is lenient in every case, as in Rails:
 * MessageVerifier#decode retries with the other alphabet and GlobalID::Verifier uses
 * urlsafe_decode64, both of which amount to Base64::urlsafeDecode.
 */
final class MessageVerifier
{
    private readonly string $digest;
    private readonly int $hexLength;

    /** @var list<self> */
    private array $fallbacks = [];

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        string $digest = 'sha1',
        private readonly bool $urlSafe = false,
        private readonly Serializer $serializer = Serializer::JsonAllowMarshal,
        private readonly bool $paddedUrlSafe = false,
    ) {
        $this->digest = strtolower($digest);
        if (!\in_array($this->digest, hash_hmac_algos(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown digest "%s".', $digest));
        }
        $this->hexLength = \strlen(hash($this->digest, ''));
    }

    /**
     * rotate / fall_back_to: a copy that tries $fallback when this verifier cannot read a message
     * (bad format or serialization; an expired or mismatched message stops at the first verifier).
     */
    public function withFallback(self $fallback): self
    {
        $verifier = clone $this;
        $verifier->fallbacks[] = $fallback;

        return $verifier;
    }

    public function generate(mixed $value, ?string $purpose = null, ?\DateTimeInterface $expiresAt = null): string
    {
        $serialized = Metadata::serialize($this->serializer, $value, $purpose, $expiresAt);
        $encoded = match (true) {
            $this->paddedUrlSafe => Base64::urlsafeEncode($serialized),
            $this->urlSafe => Base64::urlsafeEncode($serialized, false),
            default => Base64::strictEncode($serialized),
        };

        return $encoded.'--'.hash_hmac($this->digest, $encoded, $this->secret);
    }

    /** verified: the value, or null when the message is invalid, expired or for another purpose. */
    public function verified(?string $signed, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed
    {
        if (null === $signed) {
            return null;
        }

        try {
            return $this->verify($signed, $purpose, $now);
        } catch (InvalidMessage) {
            return null;
        }
    }

    /**
     * verify: like verified(), but says why a message was refused.
     *
     * @throws InvalidMessage
     */
    public function verify(string $signed, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed
    {
        $now ??= new \DateTimeImmutable();
        try {
            return $this->readMessage($signed, $purpose, $now);
        } catch (InvalidMessage $error) {
            if (!$error->rotates()) {
                throw $error;
            }
            foreach ($this->fallbacks as $fallback) {
                try {
                    return $fallback->verify($signed, $purpose, $now);
                } catch (InvalidMessage $e) {
                    if (!$e->rotates()) {
                        throw $e;
                    }
                }
            }
            throw $error;
        }
    }

    /** valid_message?: whether the signature checks out, whatever the payload. */
    public function validMessage(string $signed): bool
    {
        return null !== $this->extractEncoded($signed);
    }

    /** @throws InvalidMessage */
    private function readMessage(string $signed, ?string $purpose, \DateTimeInterface $now): mixed
    {
        $encoded = $this->extractEncoded($signed) ?? throw InvalidMessage::format('mismatched digest');
        $serialized = Base64::urlsafeDecode($encoded) ?? throw InvalidMessage::format('invalid base64');

        return Metadata::deserialize($this->serializer, $serialized, $purpose, $now, Base64::urlsafeDecode(...));
    }

    /** extract_encoded: the digest is the last hex-length characters, preceded by "--". */
    private function extractEncoded(string $signed): ?string
    {
        if (!mb_check_encoding($signed, 'UTF-8')) {
            return null;
        }
        $index = \strlen($signed) - $this->hexLength - 2;
        if ($index < 0 || '--' !== substr($signed, $index, 2)) {
            return null;
        }
        $encoded = substr($signed, 0, $index);
        $digest = substr($signed, $index + 2);
        // data.present? && digest.present?
        if (self::blank($encoded) || self::blank($digest)) {
            return null;
        }

        return hash_equals(hash_hmac($this->digest, $encoded, $this->secret), $digest) ? $encoded : null;
    }

    private static function blank(string $value): bool
    {
        return 1 === preg_match('/\A[[:space:]]*\z/u', $value);
    }
}
