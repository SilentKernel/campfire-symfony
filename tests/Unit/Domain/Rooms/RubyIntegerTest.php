<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Rooms;

use App\Domain\Rooms\RubyInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** String#to_i and Active Model's integer cast against tests/vectors/ruby_core.json. */
final class RubyIntegerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string|null}> */
    public static function vectors(): iterable
    {
        $vectors = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/vectors/ruby_core.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($vectors['strings'] as $i => $vector) {
            yield $i.': '.(json_encode($vector['input']) ?: bin2hex($vector['input'])) => [$vector['input'], $vector['to_i'], $vector['integer_cast']];
        }
    }

    #[DataProvider('vectors')]
    public function testMatchesRuby(string $input, string $toI, ?string $integerCast): void
    {
        self::assertSame($toI, RubyInteger::toI($input));
        $expected = null === $integerCast || 'ActiveModel::RangeError' === $integerCast ? null : (int) $integerCast;
        self::assertSame($expected, RubyInteger::cast($input));
    }

    public function testNonStrings(): void
    {
        self::assertSame(5, RubyInteger::cast(5));
        self::assertNull(RubyInteger::cast(null));
        self::assertNull(RubyInteger::cast(['1']));
    }
}
