<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\RailsJson;
use App\Rails\RubyFloat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RailsJsonTest extends TestCase
{
    public function testEscapesHtmlEntitiesButNotSeparatorsOrSlashes(): void
    {
        self::assertSame(
            "{\"key\":\"\\u003ca href=\\\"/x\\\"\\u003e\\u0026\\u003c/a\\u003e\u{2028}\"}",
            RailsJson::encode(['key' => "<a href=\"/x\">&</a>\u{2028}"]),
        );
        self::assertSame('"'.chr(92).'u2028'.chr(92).'u2029"', RailsJson::encode(mb_chr(0x2028).mb_chr(0x2029), escapeJsSeparators: true));
        self::assertSame('"<&>"', RailsJson::generate('<&>'));
        self::assertSame("\"\\u001f\\n\\t\\b\\f\x7fé\"", RailsJson::encode("\x1f\n\t\x08\x0c\x7fé"));
    }

    public function testEncodesPhpValuesLikeRuby(): void
    {
        self::assertSame('{"a":1,"b":[true,null,"x"],"c":{},"d":[]}', RailsJson::encode(['a' => 1, 'b' => [true, null, 'x'], 'c' => new \stdClass(), 'd' => []]));
        self::assertSame('{"1":2}', RailsJson::encode([1 => 2]));
        self::assertSame('"2026-01-01T12:00:00.123Z"', RailsJson::encode(new \DateTimeImmutable('2026-01-01T12:00:00.123456Z')));
        self::assertSame('"2026-01-01T13:00:00.000+01:00"', RailsJson::encode(new \DateTimeImmutable('2026-01-01T13:00:00+01:00')));
        self::assertSame('null', RailsJson::encode(\NAN));
        self::assertSame('{"a":1e+16,"b":[0.00001]}', RailsJson::encode(['a' => 1e16, 'b' => [1e-5]]));
    }

    /** JSON.generate(f) in the reference (json 2.21.2), from once-campfire-rust crates/rails_compat/src/json.rs. */
    /** @return iterable<string, array<mixed>> */
    public static function jsonFloats(): iterable
    {
        foreach ([
            [320.0, '320.0'], [65.84, '65.84'], [-2.5, '-2.5'], [0.0, '0.0'], [-0.0, '-0.0'], [0.1, '0.1'],
            [0.0001, '0.0001'], [0.00001, '0.00001'], [1.25e-5, '0.0000125'], [1.5e-7, '0.00000015'],
            [-1.5e-7, '-0.00000015'], [1e-7, '0.0000001'], [1.2e-9, '0.0000000012'],
            [1.23456789012e-8, '0.0000000123456789012'], [1e-10, '1e-10'], [5e-324, '5e-324'],
            [1e14, '100000000000000.0'], [-1e14, '-100000000000000.0'], [123456789012345.6, '123456789012345.6'],
            [1e15, '1e+15'], [-1e15, '-1e+15'], [1.5e15, '1.5e+15'], [1234567890123456.0, '1.234567890123456e+15'],
            [9007199254740992.0, '9.007199254740992e+15'], [1e16, '1e+16'], [12345678901234567.0, '1.2345678901234568e+16'],
            [1e21, '1e+21'], [1e100, '1e+100'], [\PHP_FLOAT_MAX, '1.7976931348623157e+308'],
        ] as [$float, $json]) {
            yield $json => [$float, $json];
        }
    }

    #[DataProvider('jsonFloats')]
    public function testFloatsMatchTheJsonGem(float $float, string $json): void
    {
        self::assertSame($json, RailsJson::generate($float));
        self::assertSame('['.$json.']', RailsJson::encode([$float]));
    }

    /** @return iterable<string, array<mixed>> */
    public static function rubyFloats(): iterable
    {
        foreach (Vectors::get('floats', 'ruby_core') as $i => $case) {
            yield $i.' '.$case['bits'] => [$case['bits'], $case['to_s']];
        }
    }

    #[DataProvider('rubyFloats')]
    public function testFloatToSMatchesRuby(string $bits, string $toS): void
    {
        $float = unpack('E', (string) hex2bin($bits))[1];
        self::assertSame($toS, RubyFloat::toS($float));
    }
}
