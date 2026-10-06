<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\Marcel\Marcel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarcelTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function samples(): iterable
    {
        foreach (StorageVectors::get('marcel') as $i => $sample) {
            yield \sprintf('#%d %s %s', $i, $sample['name'], $sample['declared_type'] ?? '-') => [$sample];
        }
    }

    /** @param array<string, mixed> $sample */
    #[DataProvider('samples')]
    public function testIdentifiesLikeMarcel(array $sample): void
    {
        $data = null !== $sample['fixture'] ? (string) file_get_contents(StorageVectors::fixture($sample['fixture'])) : (string) hex2bin($sample['data_hex']);
        self::assertSame($sample['content_type'], Marcel::identify($data, $sample['name'], $sample['declared_type']));
        self::assertSame($sample['content_type'], Marcel::identify(substr($data, 0, Marcel::magicPrefixLength()), $sample['name'], $sample['declared_type']), 'the magic prefix is enough');
    }

    public function testExtensionsAndTypes(): void
    {
        self::assertSame('image/jpeg', Marcel::forExtension('JPG'));
        self::assertSame('image/webp', Marcel::forExtension('.webp'));
        self::assertSame('application/octet-stream', Marcel::forExtension('nope'));
        self::assertSame('jpg', Marcel::extensions('image/jpeg')[0]);
        self::assertNull(Marcel::byExtension('nope'));
    }
}
