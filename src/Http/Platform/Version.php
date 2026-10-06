<?php

declare(strict_types=1);

namespace App\Http\Platform;

/**
 * `UserAgent::Version` (useragent gem 0.16.11, lib/user_agent/version.rb). Integer segments are
 * kept as digit strings without leading zeros so arbitrarily long ones compare like Ruby's
 * Integer. Equality is string equality; `<=>` compares the first six segments only, and a
 * non-numeric version sorts below anything it isn't equal to.
 */
final class Version implements \Stringable
{
    private readonly bool $blank;
    private readonly bool $comparable;

    public function __construct(private readonly string $string = '')
    {
        // `/^\s*$/`, `/^\d+$/ || /^\d+\./`
        $this->blank = 1 === preg_match('/\A[ \t\r\n\f\v]*\z/', $string);
        $this->comparable = !$this->blank && 1 === preg_match('/\A\d+(?:\z|\.)/', $string);
    }

    /** `Version#nil?`: blank. */
    public function isNil(): bool
    {
        return $this->blank;
    }

    /**
     * `to_a`: integers are tagged 'i:', strings 's:' (as the golden vectors spell them).
     *
     * @return list<array{0: 'i'|'s', 1: string}>
     */
    public function segments(): array
    {
        if ($this->blank) {
            return [];
        }
        if (!$this->comparable) {
            return [['s', $this->string]];
        }

        // `str.scan(/\d+|[A-Za-z][0-9A-Za-z-]*$/)`
        preg_match_all('/\d+|[A-Za-z][0-9A-Za-z-]*$/', $this->string, $matches);
        $segments = [];
        foreach ($matches[0] as $match) {
            $segments[] = ctype_digit($match) ? ['i', '' === ltrim($match, '0') ? '0' : ltrim($match, '0')] : ['s', $match];
        }

        return $segments;
    }

    /** First integer-or-string segment value as Ruby would see it (`to_a[$index]`). */
    public function segment(int $index): int|string|null
    {
        $segment = $this->segments()[$index] ?? null;
        if (null === $segment) {
            return null;
        }

        return 'i' === $segment[0] && \strlen($segment[1]) < 19 ? (int) $segment[1] : $segment[1];
    }

    /** `<=>` against another version (or a string, converted). */
    public function compare(self|string|null $other): int
    {
        $other = $other instanceof self ? $other : new self($other ?? '');

        if ($this->comparable) {
            $ours = $this->segments();
            $theirs = $other->segments();
            for ($i = 0; $i < 6; ++$i) {
                $a = $ours[$i] ?? ['i', '0'];
                $b = $theirs[$i] ?? ['i', '0'];
                if ('s' === $a[0] && 'i' === $b[0]) {
                    return -1;
                }
                if ('i' === $a[0] && 's' === $b[0]) {
                    return 1;
                }
                if ($a[1] === $b[1]) {
                    continue;
                }
                if ('i' === $a[0]) {
                    return \strlen($a[1]) <=> \strlen($b[1]) ?: strcmp($a[1], $b[1]) <=> 0;
                }

                return strcmp($a[1], $b[1]) <=> 0;
            }

            return 0;
        }

        return $this->string === $other->string ? 0 : -1;
    }

    public function lessThan(self|string|null $other): bool
    {
        return -1 === $this->compare($other);
    }

    /** `==`: string equality. */
    public function equals(self|string|null $other): bool
    {
        if (null === $other) {
            return $this->blank;
        }

        return $this->string === ($other instanceof self ? $other->string : $other);
    }

    public function __toString(): string
    {
        return $this->string;
    }
}
