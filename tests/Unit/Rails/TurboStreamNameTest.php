<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\TurboStreamName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TurboStreamNameTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function generateCases(): iterable
    {
        return Vectors::cases('turbo_stream_names.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('generateCases')]
    public function testSignsLikeTurbo(array $case): void
    {
        $turbo = new TurboStreamName(Vectors::keys());
        $name = $turbo->name($case['parts']);
        self::assertSame($case['stream_name'], $name);
        self::assertSame($case['signed'], $turbo->sign($name));
        self::assertSame($name, $turbo->verify($case['signed']));
    }

    /** @return iterable<string, array<mixed>> */
    public static function verifyCases(): iterable
    {
        return Vectors::cases('turbo_stream_names.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('verifyCases')]
    public function testVerifiesLikeTurbo(array $case): void
    {
        $expected = null === $case['expected'] ? null : (string) $case['expected'];
        self::assertSame($expected, new TurboStreamName(Vectors::keys())->verify($case['signed']));
    }
}
