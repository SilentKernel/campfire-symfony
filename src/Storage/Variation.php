<?php

declare(strict_types=1);

namespace App\Storage;

use App\Rails\MessageVerifier;
use App\Storage\Marcel\Marcel;

/**
 * ActiveStorage::Variation: an ordered transformations hash, its Marshal-based digest (the
 * `variation_digest` of `active_storage_variant_records`) and its signed URL key.
 *
 * Values keep Ruby's Symbol/String distinction (App\Storage\Symbol): named variants declare
 * `format: :webp`, a variation decoded from a URL key holds `format: "webp"`, and the two digest
 * differently.
 */
final readonly class Variation
{
    /** @param array<string, mixed> $transformations */
    public function __construct(public array $transformations)
    {
    }

    /** `resize_to_limit: [w, h]` plus an optional `format:` symbol, in that order. */
    public static function resizeToLimit(?int $width, ?int $height, ?string $format = null): self
    {
        $transformations = ['resize_to_limit' => [$width, $height]];
        if (null !== $format) {
            $transformations['format'] = new Symbol($format);
        }

        return new self($transformations);
    }

    /** `Variation.decode(key)`: verified with purpose "variation"; string values stay strings. */
    public static function decode(MessageVerifier $verifier, string $key, ?\DateTimeInterface $now = null): ?self
    {
        $data = $verifier->verified($key, 'variation', $now);

        return \is_array($data) && (!array_is_list($data) || [] === $data) ? new self(self::stringKeys($data)) : null;
    }

    /**
     * `default_to(defaults)`: `transformations.reverse_merge(defaults)`, i.e. `defaults.merge(self)`:
     * default keys come first and keep their position when overridden.
     *
     * @param array<string, mixed> $defaults
     */
    public function defaultTo(array $defaults): self
    {
        $merged = $defaults;
        foreach ($this->transformations as $key => $value) {
            $merged[$key] = $value;
        }

        return new self($merged);
    }

    public function isEmpty(): bool
    {
        return [] === $this->transformations;
    }

    /** `OpenSSL::Digest::SHA1.base64digest Marshal.dump(transformations)` */
    public function digest(): string
    {
        return base64_encode(sha1(RubyMarshal::dump($this->transformations), true));
    }

    /** `Variation#key`: `ActiveStorage.verifier.generate(transformations, purpose: :variation)`. */
    public function key(MessageVerifier $verifier): string
    {
        return $verifier->generate(self::plain($this->transformations), 'variation');
    }

    /**
     * `transformations.fetch(:format, :png)`, which must be an extension Marcel knows.
     *
     * @throws InvalidVariation
     */
    public function format(): string
    {
        $format = $this->transformations['format'] ?? 'png';
        if ($format instanceof Symbol) {
            $format = $format->name;
        }
        if (!\is_string($format) || null === Marcel::byExtension($format)) {
            throw new InvalidVariation(\sprintf('Invalid variant format (%s)', get_debug_type($format)));
        }

        return $format;
    }

    /** `Marcel::MimeType.for(extension: format)` */
    public function contentType(): string
    {
        return Marcel::forExtension($this->format());
    }

    /** The transformations as the verifier serializes them (symbols become strings). */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof Symbol) {
            return $value->name;
        }
        if (\is_array($value)) {
            $plain = array_map(self::plain(...), $value);

            return [] === $value || array_is_list($value) ? $plain : (object) $plain;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
