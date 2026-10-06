<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\Http\Responses;
use App\Tests\Unit\Rails\Vectors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResponsesTest extends TestCase
{
    public function testByteRangesMatchRack(): void
    {
        foreach (Vectors::get('byte_ranges', 'ruby_core') as $case) {
            self::assertSame($case['ranges'], Responses::byteRanges($case['header'], $case['size']), json_encode($case) ?: '');
        }
    }

    public function testToIMatchesRuby(): void
    {
        foreach (Vectors::get('strings', 'ruby_core') as $case) {
            $expected = $case['to_i'];
            if (\strlen(ltrim(ltrim($expected, '-'), '0')) > 18) {
                continue; // Bignums
            }
            self::assertSame((int) $expected, Responses::rubyToI($case['input']), json_encode($case['input']) ?: '');
        }
    }

    public function testCacheControlLikeRails(): void
    {
        $response = new Response();
        Responses::expiresIn($response, 1800, public: true, staleWhileRevalidate: 604800);
        self::assertSame('max-age=1800, public, stale-while-revalidate=604800', $response->headers->get('Cache-Control'));
        Responses::expiresIn($response, Responses::HUNDRED_YEARS, public: true, immutable: true);
        self::assertSame('max-age=3155695200, public, immutable', $response->headers->get('Cache-Control'));
        Responses::expiresIn($response, 300);
        self::assertSame('max-age=300, private', $response->headers->get('Cache-Control'));
    }

    public function testWeakEtagsLikeRails(): void
    {
        // Captured from the reference: GET /rails/active_storage/blobs/proxy/... (http_cache_forever)
        self::assertSame('W/"ab9a8912f562f1a8b0322f6bea1afa82"', Responses::weakEtag(['/rails/active_storage/blobs/proxy/eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad/moon.jpg']));
        self::assertSame('users/149087659-20260125160000000000', Responses::cacheKeyWithVersion('users', 149087659, new \DateTimeImmutable('2026-01-25 16:00:00 UTC')));
        self::assertSame('W/"a0eab39abba52aa6f852cb93e1244bd7"', Responses::weakEtag(['users/149087659-20260125160000000000', 'd500db55e2a67222018ef0156839c3c9']));
    }

    public function testFreshness(): void
    {
        $etag = 'W/"abc"';
        $lastModified = new \DateTimeImmutable('2011-01-01 00:00:00 UTC');
        self::assertFalse(Responses::isFresh(Request::create('/'), $etag, $lastModified));
        self::assertTrue(Responses::isFresh(Request::create('/', server: ['HTTP_IF_NONE_MATCH' => 'W/"x", W/"abc"']), $etag));
        self::assertTrue(Responses::isFresh(Request::create('/', server: ['HTTP_IF_NONE_MATCH' => '*']), $etag));
        self::assertFalse(Responses::isFresh(Request::create('/', server: ['HTTP_IF_NONE_MATCH' => 'W/"x"']), $etag));
        self::assertTrue(Responses::isFresh(Request::create('/', server: ['HTTP_IF_MODIFIED_SINCE' => 'Sat, 01 Jan 2011 00:00:00 GMT']), $etag, $lastModified));
        self::assertFalse(Responses::isFresh(Request::create('/', server: ['HTTP_IF_MODIFIED_SINCE' => 'Fri, 31 Dec 2010 00:00:00 GMT']), $etag, $lastModified));
    }
}
