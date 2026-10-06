<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/** docker/php.ini settings that change what responses look like. */
final class PhpIniTest extends TestCase
{
    /**
     * Rails' `head :forbidden` is "text/html" without a charset; with a default_charset, PHP's SAPI
     * would send "text/html;charset=UTF-8" (and the same for text/vnd.turbo-stream.html).
     */
    public function testNoDefaultCharsetIsAppendedToTextContentTypes(): void
    {
        $ini = parse_ini_file(\dirname(__DIR__, 3).'/docker/php.ini', true, \INI_SCANNER_RAW);
        self::assertIsArray($ini);
        self::assertSame('', $ini['PHP']['default_charset'] ?? null);

        // In the image (bin/check), the file is loaded.
        if (str_contains((string) php_ini_scanned_files(), 'zz-campfire.ini')) {
            self::assertSame('', \ini_get('default_charset'));
        }
    }
}
