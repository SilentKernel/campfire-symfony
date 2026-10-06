<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * A Ruby Symbol inside a transformations hash (`format: :webp`). Active Storage digests
 * transformations with Marshal, where `:webp` and `"webp"` differ: named variants carry symbols,
 * variations decoded from a URL key carry strings.
 */
final readonly class Symbol implements \JsonSerializable, \Stringable
{
    public function __construct(public string $name)
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function jsonSerialize(): string
    {
        return $this->name;
    }
}
