<?php

declare(strict_types=1);

namespace App\Http\Platform;

/** One `UserAgent` of a parsed header: product, version and the comment split on "; ". */
final readonly class Product implements \Stringable
{
    public Version $version;

    /** @param list<string>|null $comment */
    public function __construct(public string $product, ?string $version = null, public ?array $comment = null)
    {
        $this->version = new Version($version ?? '');
    }

    /** @return list<string>|null */
    public static function parseComment(?string $comment): ?array
    {
        if (null === $comment) {
            return null;
        }
        // String#split drops trailing empty fields ("".split("; ") is []).
        $parts = explode('; ', $comment);
        while ([] !== $parts && '' === end($parts)) {
            array_pop($parts);
        }

        return $parts;
    }

    /** `detect_comment { |c| ... }` */
    public function detectComment(callable $predicate): ?string
    {
        foreach ($this->comment ?? [] as $comment) {
            if ($predicate($comment)) {
                return $comment;
            }
        }

        return null;
    }

    public function commentAt(int $index): ?string
    {
        if (null === $this->comment) {
            throw RubyError::noMethod('[]');
        }

        return $this->comment[$index] ?? null;
    }

    /** `to_str` */
    public function __toString(): string
    {
        if (!$this->version->isNil() && null !== $this->comment) {
            return $this->product.'/'.$this->version.' ('.implode('; ', $this->comment).')';
        }
        if (!$this->version->isNil()) {
            return $this->product.'/'.$this->version;
        }
        if (null !== $this->comment) {
            return $this->product.' ('.implode('; ', $this->comment).')';
        }

        return $this->product;
    }
}
