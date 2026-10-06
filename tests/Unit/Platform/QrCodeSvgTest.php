<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Domain\Users\QrCodeSvg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * fixtures/rqrcode_svg.json: SHA-256 digests of `RQRCode::QRCode.new(input).as_svg(viewbox: true,
 * fill: :white, color: :black)` from the reference image (byte, alphanumeric and numeric modes,
 * versions 1 to 10+).
 */
final class QrCodeSvgTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rqrcode(): iterable
    {
        foreach (json_decode((string) file_get_contents(__DIR__.'/fixtures/rqrcode_svg.json'), true, 512, \JSON_THROW_ON_ERROR) as $case) {
            yield substr($case['input'], 0, 50) => [$case['input'], $case['sha256']];
        }
    }

    #[DataProvider('rqrcode')]
    public function testSvgIsByteForByteRqrcodes(string $input, string $sha256): void
    {
        self::assertSame($sha256, hash('sha256', QrCodeSvg::render($input)));
    }
}
