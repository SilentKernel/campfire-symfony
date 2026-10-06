<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ContentDisposition;
use App\Storage\Filename;
use App\Storage\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function filenames(): iterable
    {
        foreach (StorageVectors::get('filenames') as $i => $vector) {
            yield '#'.$i.' '.$vector['sanitized'] => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('filenames')]
    public function testSanitizesAndFormatsLikeRails(array $vector): void
    {
        $filename = new Filename((string) hex2bin($vector['input_hex']));
        self::assertSame($vector['sanitized'], $filename->sanitized());
        self::assertSame($vector['base_hex'], bin2hex($filename->base()));
        self::assertSame($vector['extension_hex'], bin2hex($filename->extension()));
        self::assertSame($vector['inline'], ContentDisposition::format('inline', $filename->sanitized()));
        self::assertSame($vector['attachment'], ContentDisposition::format('attachment', $filename->sanitized()));
        self::assertSame($vector['escaped_path'], Paths::escapePath($filename->sanitized()));
    }

    /** @return iterable<array{string, string, string}> */
    public static function rubyFileSemantics(): iterable
    {
        yield ['foo.', '.', 'foo'];
        yield ['a.b.', '.', 'a.b'];
        yield ['.bashrc', '', '.bashrc'];
        yield ['..', '', '..'];
        yield ['.a.b', '.b', '.a'];
        yield ['a..b', '.b', 'a.'];
        yield ['a/b.c/d', '', 'd'];
        yield ['x.tar.gz', '.gz', 'x.tar'];
        yield ['', '', ''];
        yield ['é.png', '.png', 'é'];
    }

    #[DataProvider('rubyFileSemantics')]
    public function testExtnameAndBasenameFollowRuby(string $name, string $extension, string $base): void
    {
        $filename = new Filename($name);
        self::assertSame($extension, $filename->extensionWithDelimiter());
        self::assertSame($base, $filename->base());
    }

    public function testTransliteratesBeyondLatin1(): void
    {
        self::assertSame("inline; filename=\"Lodz x.pdf\"; filename*=UTF-8''%C5%81%C3%B3d%C5%BA%20%C3%97.pdf", ContentDisposition::format('inline', 'Łódź ×.pdf'));
    }
}
