<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\Base64;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyGeneratorTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function keyCases(): iterable
    {
        return Vectors::cases('key_generator');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('keyCases')]
    public function testDerivesRailsKeys(array $case): void
    {
        self::assertSame($case['key_hex'], bin2hex(Vectors::keys()->generateKey($case['salt'], $case['length'])));
    }

    public function testBase64IsAsStrictAsRuby(): void
    {
        self::assertSame('A', Base64::strictDecode('QQ=='));
        self::assertNull(Base64::strictDecode('QQ'));
        self::assertNull(Base64::strictDecode('QR=='));
        self::assertNull(Base64::strictDecode('QQ='));
        self::assertSame('', Base64::strictDecode(''));
        self::assertSame('A', Base64::urlsafeDecode('QQ'));
        self::assertSame('A', Base64::urlsafeDecode('QQ=='));
        self::assertNull(Base64::urlsafeDecode('QQ='));
        self::assertNull(Base64::urlsafeDecode('QR'));
        self::assertSame(Base64::urlsafeDecode('a+b/'), Base64::urlsafeDecode('a-b_'));
        self::assertSame('-_8=', Base64::urlsafeEncode("\xfb\xff"));
        self::assertSame('QQ', Base64::urlsafeEncode('A', false));
    }
}
