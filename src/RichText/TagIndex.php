<?php

declare(strict_types=1);

namespace App\RichText;

/**
 * What rails_autolink's `auto_linked?(left, right)` asks about the text around a match, where
 * `left` is everything before it and `right` everything after:
 *
 *     (left =~ /<[^>]+$/ and right =~ /^[^>]*>/) or (left.rindex(/<a\b.*?>/i) and $' !~ /<\/a>/i)
 *
 * rails_autolink runs those regular expressions over the whole of `left` for every match, which
 * is quadratic; this indexes the text once so that each question is a binary search, with the
 * same answers (the Rust port's TagIndex, which is tested against the regular expressions).
 *
 * @internal
 */
final class TagIndex
{
    /** @var list<int> positions of `<` */
    private array $lts = [];
    /** @var list<int> positions of `>` */
    private array $gts = [];
    /** The first `\n` that ends a line inside an unclosed tag, after which `/<[^>]+$/` matches every `left`. */
    private ?int $firstDanglingNewline = null;
    /** @var list<array{int, int}> `/<a\b.*?>/i` matches, ascending and disjoint */
    private array $openAnchors = [];
    /** @var list<int> positions of `</a>`, in any case */
    private array $closeAnchors = [];

    public function __construct(string $text)
    {
        $length = \strlen($text);
        $unclosedLt = null;
        for ($i = 0; $i < $length; ++$i) {
            $b = $text[$i];
            if ('<' === $b) {
                $this->lts[] = $i;
                $unclosedLt ??= $i;
                if (0 === strncasecmp(substr($text, $i + 1, 3), '/a>', 3)) {
                    $this->closeAnchors[] = $i;
                }
                $previous = end($this->openAnchors);
                if ((false === $previous || $previous[1] <= $i) && 1 === preg_match('/\G<a\b[^\n]*?>/i', $text, $m, 0, $i)) {
                    $this->openAnchors[] = [$i, $i + \strlen($m[0])];
                }
            } elseif ('>' === $b) {
                $this->gts[] = $i;
                $unclosedLt = null;
            } elseif ("\n" === $b && null === $this->firstDanglingNewline && null !== $unclosedLt && $unclosedLt + 2 <= $i) {
                $this->firstDanglingNewline = $i;
            }
        }
    }

    /** `auto_linked?(text[0, start], text[end..])`: inside a tag, or inside an unclosed `<a>`. */
    public function autoLinked(int $start, int $end): bool
    {
        return ($this->openTagAtLineEnd($start) && $this->closesTag($end)) || $this->insideAnchor($start);
    }

    /** `left =~ /<[^>]+$/` */
    private function openTagAtLineEnd(int $start): bool
    {
        if (null !== $this->firstDanglingNewline && $this->firstDanglingNewline < $start) {
            return true;
        }
        // At the end of `left`: a `<` after the last `>`, with at least one character after it
        $gtCount = self::partitionPoint($this->gts, static fn (int $p): bool => $p < $start);
        $lastGt = $gtCount > 0 ? $this->gts[$gtCount - 1] : null;
        $ltIndex = self::partitionPoint($this->lts, static fn (int $p): bool => null !== $lastGt && $p <= $lastGt);
        $firstLtAfter = $this->lts[$ltIndex] ?? null;

        return null !== $firstLtAfter && $firstLtAfter + 2 <= $start;
    }

    /** `right =~ /^[^>]*>/`, which matches whenever `right` has a `>` at all. */
    private function closesTag(int $end): bool
    {
        $last = end($this->gts);

        return false !== $last && $last >= $end;
    }

    /** The last `<a ...>` wholly in `left` isn't closed before `left` ends. */
    private function insideAnchor(int $start): bool
    {
        $before = self::partitionPoint($this->openAnchors, static fn (array $a): bool => $a[1] <= $start);
        if (0 === $before) {
            return false;
        }
        $anchorEnd = $this->openAnchors[$before - 1][1];
        $close = $this->closeAnchors[self::partitionPoint($this->closeAnchors, static fn (int $p): bool => $p < $anchorEnd)] ?? null;

        return !(null !== $close && $close + 4 <= $start);
    }

    /**
     * The number of leading elements for which `$predicate` holds (it must hold for a prefix).
     *
     * @template T
     *
     * @param list<T>           $items
     * @param callable(T): bool $predicate
     */
    private static function partitionPoint(array $items, callable $predicate): int
    {
        $low = 0;
        $high = \count($items);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($predicate($items[$mid])) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }
}
