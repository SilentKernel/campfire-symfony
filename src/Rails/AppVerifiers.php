<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Rails.application.message_verifier(name) (= message_verifiers[name]) with the app defaults: key
 * generate_key(name, 64), HMAC-SHA1, strict Base64, :json_allow_marshal and the {"_rails":{"data"}}
 * envelope. Active Storage uses verifier("ActiveStorage") for blob signed ids (purpose "blob_id",
 * not SignedId's "model/purpose" scheme), blob keys, variation keys and direct-upload tokens.
 */
final class AppVerifiers
{
    /** @var array<string, MessageVerifier> */
    private array $verifiers = [];

    public function __construct(private readonly KeyGenerator $keys)
    {
    }

    public function verifier(string $name): MessageVerifier
    {
        return $this->verifiers[$name] ??= new MessageVerifier($this->keys->generateKey($name), 'sha1', false, Serializer::JsonAllowMarshal);
    }
}
