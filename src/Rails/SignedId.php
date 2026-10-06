<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * ActiveRecord::SignedId (record.signed_id(purpose:, expires_at:) / Model.find_signed).
 *
 * The verifier is message_verifiers["active_record/signed_id"] with the legacy options prepended
 * (use_legacy_signed_id_verifier defaults to :generate_and_verify): it generates with SHA256,
 * ::JSON and URL-safe Base64, and falls back when reading to the app-wide default (SHA1,
 * :json_allow_marshal, strict Base64). The purpose is "<base class name underscored>/<purpose>".
 */
final class SignedId
{
    public const string SALT = 'active_record/signed_id';

    private ?MessageVerifier $verifier = null;

    public function __construct(private readonly KeyGenerator $keys)
    {
    }

    /** @param string $modelName the record's base class name ("User", "Room", not "Rooms::Open") */
    public function generate(int $id, string $modelName, ?string $purpose, ?\DateTimeInterface $expiresAt = null): string
    {
        return $this->verifier()->generate($id, self::combinePurposes($modelName, $purpose), $expiresAt);
    }

    /** find_signed's verification: the id to look up, or null. */
    public function find(string $signed, string $modelName, ?string $purpose, ?\DateTimeInterface $now = null): ?int
    {
        $id = $this->verifier()->verified($signed, self::combinePurposes($modelName, $purpose), $now);

        return match (true) {
            \is_int($id) => $id,
            // find_by(id: "7") casts the string like ActiveModel::Type::Integer.
            \is_string($id) && 1 === preg_match('/\A\s*([+-]?\d+)/', $id, $m) => (int) $m[1],
            \is_float($id) && is_finite($id) => (int) $id,
            default => null,
        };
    }

    /** combine_signed_id_purposes: [base_class.name.underscore, purpose.to_s].compact_blank.join("/"). */
    public static function combinePurposes(string $modelName, ?string $purpose): string
    {
        $parts = array_filter([self::underscore($modelName), $purpose ?? ''], static fn (string $part): bool => '' !== trim($part));

        return implode('/', $parts);
    }

    /** String#underscore: "Rooms::Open" → "rooms/open", "WebPush" → "web_push", "HTTPRequest" → "http_request". */
    public static function underscore(string $name): string
    {
        $word = str_replace('::', '/', $name);
        $word = preg_replace(['/([A-Z\d]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'], '$1_$2', $word) ?? $word;

        return strtolower(str_replace('-', '_', $word));
    }

    private function verifier(): MessageVerifier
    {
        if (null === $this->verifier) {
            $secret = $this->keys->generateKey(self::SALT);
            $this->verifier = new MessageVerifier($secret, 'sha256', true, Serializer::Json)
                ->withFallback(new MessageVerifier($secret, 'sha1', false, Serializer::JsonAllowMarshal));
        }

        return $this->verifier;
    }
}
