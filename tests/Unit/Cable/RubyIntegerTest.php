<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cable;

use App\Cable\Server\RubyInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RubyIntegerTest extends TestCase
{
    /** @return iterable<array{mixed, ?int}> */
    public static function casts(): iterable
    {
        yield [12, 12];
        yield ['12', 12];
        yield ['12abc', 12];
        yield ['  -3', -3];
        yield ['1_000', 1000];
        yield ['abc', 0];
        yield ['', null];
        yield ['   ', null];
        yield [1.9, 1];
        yield [true, 1];
        yield [false, 0];
        yield [null, null];
        yield [[1], null];
        yield ['99999999999999999999', null];
    }

    #[DataProvider('casts')]
    public function testCastsLikeActiveModel(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, RubyInteger::cast($value));
    }
}
