<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Database\Type\RailsDateTimeType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RailsDateTimeTypeTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function formats(): iterable
    {
        // ActiveRecord::ConnectionAdapters::Quoting#quoted_date: ".%06d" only when usec > 0.
        yield 'whole seconds' => ['2026-03-02 16:00:00', '2026-03-02T16:00:00Z'];
        yield 'microseconds' => ['2026-03-02 16:00:00.000042', '2026-03-02T16:00:00.000042Z'];
        yield 'converted to UTC' => ['2026-03-02 15:00:00.500000', '2026-03-02T16:00:00.500000+01:00'];
    }

    #[DataProvider('formats')]
    public function testFormatsLikeActiveRecord(string $expected, string $time): void
    {
        self::assertSame($expected, (new RailsDateTimeType())->convertToDatabaseValue(new \DateTimeImmutable($time), new SQLitePlatform()));
    }

    public function testParsesWhatRailsAndSqliteStore(): void
    {
        $type = new RailsDateTimeType();
        $platform = new SQLitePlatform();

        self::assertSame('2026-03-02 16:00:00.000000 UTC', $type->convertToPHPValue('2026-03-02 16:00:00', $platform)?->format('Y-m-d H:i:s.u e'));
        self::assertSame('2026-03-02 16:00:00.123456 UTC', $type->convertToPHPValue('2026-03-02 16:00:00.123456', $platform)?->format('Y-m-d H:i:s.u e'));
        self::assertSame('2026-03-02 16:00:00.123000', $type->convertToPHPValue('2026-03-02 16:00:00.123', $platform)?->format('Y-m-d H:i:s.u'));
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull(RailsDateTimeType::parse('2026-02-30 16:00:00'));
    }

    public function testRoundTripKeepsTheInstant(): void
    {
        $type = new RailsDateTimeType();
        $platform = new SQLitePlatform();
        $time = new \DateTimeImmutable('2026-03-02 17:00:00.654321', new \DateTimeZone('Europe/Paris'));

        $back = $type->convertToPHPValue($type->convertToDatabaseValue($time, $platform), $platform);

        self::assertEquals($time, $back);
        self::assertSame('UTC', $back?->getTimezone()->getName());
        self::assertSame('654321', $back->format('u'));
    }
}
