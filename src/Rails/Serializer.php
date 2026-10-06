<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * The message serializers Campfire's verifiers and encryptors use.
 */
enum Serializer
{
    /**
     * ActiveSupport::MessageEncryptor::NullSerializer: the value is a string of already-dumped
     * bytes (the cookie jars). Uses the legacy {"_rails":{"message":base64,"exp","pur"}} envelope.
     */
    case Null;

    /** The ::JSON module (signed ids' legacy verifier, Turbo stream names). */
    case Json;

    /** SerializerWithFallback[:json] (ActiveSupport::JSON, Marshal payloads rejected). */
    case JsonWithFallback;

    /**
     * SerializerWithFallback[:json_allow_marshal], the app's message_serializer under
     * load_defaults 7.1+ (Rails.application.message_verifiers, SGIDs). Marshal payloads are read
     * when they are a single String (Rails 7.0-era SGIDs); anything else is a serialization error.
     */
    case JsonAllowMarshal;

    public function dump(mixed $value): string
    {
        return match ($this) {
            self::Null => \is_string($value) ? $value : throw new \InvalidArgumentException('The null serializer only signs strings.'),
            self::Json => RailsJson::generate($value),
            self::JsonWithFallback, self::JsonAllowMarshal => RailsJson::encode($value),
        };
    }

    /** @throws InvalidMessage */
    public function load(string $dumped): mixed
    {
        if (self::Null === $this) {
            return $dumped;
        }
        if (self::Json === $this && '' === $dumped) {
            return null; // JSON.load("") is nil
        }
        if (self::Json !== $this && str_starts_with($dumped, Marshal::SIGNATURE)) {
            if (self::JsonAllowMarshal !== $this) {
                throw InvalidMessage::serialization('Marshal payloads are not allowed');
            }

            return Marshal::loadString($dumped) ?? throw InvalidMessage::serialization('Unsupported Marshal payload');
        }

        try {
            return RailsJson::decode($dumped);
        } catch (\JsonException $e) {
            throw InvalidMessage::serialization($e->getMessage());
        }
    }

    /** Whether Rails wraps metadata with this serializer (Messages::Metadata::ENVELOPE_SERIALIZERS). */
    public function usesEnvelope(): bool
    {
        return self::Null !== $this;
    }
}
