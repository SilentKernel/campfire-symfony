<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The subset of Ruby's `Marshal.dump` (format 4.8) that transformations hashes need, for
 * `ActiveStorage::Variation#digest` (`SHA1.base64digest(Marshal.dump(transformations))`).
 *
 * PHP values map to Ruby as: null → nil, bool, int (Fixnum or Bignum), string → UTF-8 String,
 * Symbol → Symbol, list → Array, string-keyed array → Hash with symbol keys (Variation symbolizes
 * keys, `deep_symbolize_keys`).
 */
final class RubyMarshal
{
    /** @var list<string> */
    private array $symbols = [];

    private string $out = "\x04\x08";

    private function __construct()
    {
    }

    public static function dump(mixed $value): string
    {
        $writer = new self();
        $writer->value($value);

        return $writer->out;
    }

    private function value(mixed $value): void
    {
        match (true) {
            null === $value => $this->out .= '0',
            true === $value => $this->out .= 'T',
            false === $value => $this->out .= 'F',
            \is_int($value) => $this->integer($value),
            $value instanceof Symbol => $this->symbol($value->name),
            \is_string($value) => $this->string($value),
            \is_array($value) && array_is_list($value) => $this->array($value),
            \is_array($value) => $this->hash($value),
            default => throw new \InvalidArgumentException(\sprintf('Cannot marshal %s.', get_debug_type($value))),
        };
    }

    /** A String with an encoding: an ivar list holding `E: true` (UTF-8). */
    private function string(string $value): void
    {
        $this->out .= 'I"';
        $this->bytes($value);
        $this->long(1);
        $this->symbol('E');
        $this->out .= 'T';
    }

    /** @param list<mixed> $items */
    private function array(array $items): void
    {
        $this->out .= '[';
        $this->long(\count($items));
        foreach ($items as $item) {
            $this->value($item);
        }
    }

    /** @param array<array-key, mixed> $entries */
    private function hash(array $entries): void
    {
        $this->out .= '{';
        $this->long(\count($entries));
        foreach ($entries as $key => $item) {
            $this->symbol((string) $key);
            $this->value($item);
        }
    }

    /** Fixnums whose tagged VALUE fits 31 bits are written inline (`i`), others as Bignums (`l`). */
    private function integer(int $n): void
    {
        if ($n >= -(1 << 30) && $n < (1 << 30)) {
            $this->out .= 'i';
            $this->long($n);

            return;
        }
        $this->out .= 'l'.($n < 0 ? '-' : '+');
        $magnitude = PHP_INT_MIN === $n ? null : abs($n);
        $digits = '';
        if (null === $magnitude) {
            $digits = "\x00\x00\x00\x00\x00\x00\x00\x80";
        } else {
            while ($magnitude > 0) {
                $digits .= \chr($magnitude & 0xFF);
                $magnitude >>= 8;
            }
        }
        if (1 === \strlen($digits) % 2) {
            $digits .= "\x00";
        }
        $this->long(intdiv(\strlen($digits), 2));
        $this->out .= $digits;
    }

    private function symbol(string $name): void
    {
        $index = array_search($name, $this->symbols, true);
        if (false !== $index) {
            $this->out .= ';';
            $this->long($index);

            return;
        }
        $this->symbols[] = $name;
        $this->out .= ':';
        $this->bytes($name);
    }

    private function bytes(string $bytes): void
    {
        $this->long(\strlen($bytes));
        $this->out .= $bytes;
    }

    /** `w_long` in marshal.c. */
    private function long(int $n): void
    {
        if (0 === $n) {
            $this->out .= "\x00";

            return;
        }
        if ($n > 0 && $n < 123) {
            $this->out .= \chr($n + 5);

            return;
        }
        if ($n < 0 && $n > -124) {
            $this->out .= \chr(($n - 5) & 0xFF);

            return;
        }
        $buf = '';
        $x = $n;
        for ($i = 1; $i <= 8; ++$i) {
            $buf .= \chr($x & 0xFF);
            $x >>= 8;
            if (0 === $x) {
                $this->out .= \chr($i).$buf;

                return;
            }
            if (-1 === $x) {
                $this->out .= \chr((-$i) & 0xFF).$buf;

                return;
            }
        }
    }
}
