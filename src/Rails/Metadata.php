<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * ActiveSupport::Messages::Metadata: how a value, its purpose and its expiry are packed into the
 * bytes that get signed or encrypted (activesupport/lib/active_support/messages/metadata.rb).
 *
 * With a serializer Rails trusts for metadata the envelope is
 * {"_rails":{"data":<value>,"exp":..,"pur":..}} in that serializer, with absent keys left out.
 * With the cookie jars' NullSerializer it is the legacy "dual-serialized" one,
 * {"_rails":{"message":"<strict base64 of the dumped value>","exp":..,"pur":..}}, always carrying
 * exp and pur (null when unset), encoded with ActiveSupport::JSON.
 *
 * @internal
 */
final class Metadata
{
    private const string LEGACY_PREFIX = '{"_rails":{"message":';

    public static function serialize(Serializer $serializer, mixed $value, ?string $purpose, ?\DateTimeInterface $expiresAt): string
    {
        $dumped = $serializer->dump($value);
        if (null === $purpose && null === $expiresAt) {
            return $dumped;
        }
        $expiry = null === $expiresAt ? null : self::iso8601($expiresAt);

        if (!$serializer->usesEnvelope()) {
            return RailsJson::encode(['_rails' => ['message' => Base64::strictEncode($dumped), 'exp' => $expiry, 'pur' => $purpose]]);
        }

        // Splice the dumped value in so it keeps the serializer's own escaping.
        $json = '{"_rails":{"data":'.$dumped;
        if (null !== $expiry) {
            $json .= ',"exp":'.$serializer->dump($expiry);
        }
        if (null !== $purpose) {
            $json .= ',"pur":'.$serializer->dump($purpose);
        }

        return $json.'}}';
    }

    /**
     * deserialize_with_metadata.
     *
     * @param \Closure(string): ?string $decodeLegacyMessage decodes the base64 inside a legacy envelope
     *
     * @throws InvalidMessage
     */
    public static function deserialize(Serializer $serializer, string $message, ?string $purpose, \DateTimeInterface $now, \Closure $decodeLegacyMessage): mixed
    {
        if (str_starts_with($message, self::LEGACY_PREFIX)) {
            try {
                $envelope = RailsJson::decode($message);
            } catch (\JsonException $e) {
                throw InvalidMessage::format($e->getMessage());
            }
            $rails = self::extract($envelope, $purpose, $now);
            $encoded = $rails['message'] ?? null;
            if (!\is_string($encoded) || null === $dumped = $decodeLegacyMessage($encoded)) {
                throw InvalidMessage::format('invalid legacy message');
            }

            return $serializer->load($dumped);
        }

        $deserialized = $serializer->load($message);
        if (\is_array($deserialized) && \array_key_exists('_rails', $deserialized)) {
            return self::extract($deserialized, $purpose, $now)['data'] ?? null;
        }
        if (null === $purpose) {
            return $deserialized;
        }

        throw InvalidMessage::content('missing metadata');
    }

    /** Time#iso8601(3) in UTC, the fraction truncated: "2046-01-01T12:00:00.000Z". */
    public static function iso8601(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Time.iso8601; null where Ruby raises ArgumentError. */
    public static function parseIso8601(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/', $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Object#to_s for the JSON values a purpose could hold. */
    public static function rubyToS(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => RubyFloat::toS($value),
            // Arrays and hashes to_s as their #inspect, which no purpose we compare against looks like.
            default => "\0".serialize($value),
        };
    }

    /**
     * extract_from_metadata_envelope: expired when now >= exp; purposes compare with to_s, so a
     * missing "pur" matches no purpose.
     *
     * @param array<array-key, mixed> $envelope
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidMessage
     */
    private static function extract(mixed $envelope, ?string $purpose, \DateTimeInterface $now): array
    {
        $rails = \is_array($envelope) ? ($envelope['_rails'] ?? null) : null;
        if (!\is_array($rails)) {
            throw InvalidMessage::content('malformed metadata');
        }

        $exp = $rails['exp'] ?? null;
        if (null !== $exp && false !== $exp) {
            $expiry = self::parseIso8601($exp) ?? throw InvalidMessage::content('malformed expiry');
            if ($now >= $expiry) {
                throw InvalidMessage::content('expired');
            }
        }

        if (self::rubyToS($rails['pur'] ?? null) !== ($purpose ?? '')) {
            throw InvalidMessage::content('mismatched purpose');
        }

        return $rails;
    }
}
