<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Turbo::StreamsChannel.signed_stream_name / verified_stream_name (turbo-rails
 * app/channels/turbo/streams/stream_name.rb, lib/turbo-rails.rb). The verifier is
 * MessageVerifier.new(generate_key("turbo/signed_stream_verifier_key"), digest: "SHA256",
 * serializer: JSON): strict Base64 of the JSON-dumped name, no metadata envelope.
 */
final class TurboStreamName
{
    public const string SALT = 'turbo/signed_stream_verifier_key';

    private ?MessageVerifier $verifier = null;

    public function __construct(private readonly KeyGenerator $keys)
    {
    }

    /**
     * stream_name_from: parts already resolved (a record is GlobalId::param(GlobalId::gid(...)),
     * a symbol or string itself), joined with ":".
     *
     * @param list<string> $parts
     */
    public function name(array $parts): string
    {
        return implode(':', $parts);
    }

    public function sign(string $streamName): string
    {
        return $this->verifier()->generate($streamName);
    }

    /** verified_stream_name: the stream name, or null. Numbers come back as their to_s. */
    public function verify(?string $signed): ?string
    {
        $name = $this->verifier()->verified($signed);

        return match (true) {
            \is_string($name) => $name,
            \is_int($name) => (string) $name,
            \is_float($name) => RubyFloat::toS($name),
            default => null,
        };
    }

    private function verifier(): MessageVerifier
    {
        return $this->verifier ??= new MessageVerifier($this->keys->generateKey(self::SALT), 'sha256', false, Serializer::Json);
    }
}
