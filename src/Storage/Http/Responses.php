<?php

declare(strict_types=1);

namespace App\Storage\Http;

use App\Http\Mime;
use App\Http\RackResponseHeaderBag;
use App\Storage\ContentDisposition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ActionController pieces the storage controllers use: `head`, `expires_in`,
 * `stale?`/`fresh_when` (weak ETags), `send_file` and `send_data`/`send_stream` headers.
 */
final class Responses
{
    /** `100.years` in seconds (`http_cache_forever`). */
    public const int HUNDRED_YEARS = 3_155_695_200;

    /**
     * `head status`. Inside the action the body is typed as the request format; from a
     * before_action (`formats` isn't set yet) it is text/html.
     */
    public static function head(Request $request, int $status, bool $fromBeforeAction = false): Response
    {
        $response = new Response('', $status);
        if (!\in_array($status, [204, 205, 304], true) && $status >= 200) {
            $format = $fromBeforeAction ? null : Mime::format($request);
            $response->headers->set('Content-Type', null === $format || Mime::ALL === $format ? 'text/html' : Mime::typeOf($format));
        }

        return $response;
    }

    /**
     * `expires_in seconds, public:, must_revalidate:, stale_while_revalidate:, immutable:` as
     * ActionDispatch::Http::Cache::Response serializes it.
     */
    public static function expiresIn(Response $response, int $seconds, bool $public = false, bool $mustRevalidate = false, ?int $staleWhileRevalidate = null, bool $immutable = false): void
    {
        $directives = ['max-age='.$seconds, $public ? 'public' : 'private'];
        if ($mustRevalidate) {
            $directives[] = 'must-revalidate';
        }
        if (null !== $staleWhileRevalidate) {
            $directives[] = 'stale-while-revalidate='.$staleWhileRevalidate;
        }
        if ($immutable) {
            $directives[] = 'immutable';
        }
        self::setCacheControl($response, implode(', ', $directives));
    }

    /** `expires_now` */
    public static function expiresNow(Response $response): void
    {
        self::setCacheControl($response, 'no-cache');
    }

    /** Sets Cache-Control verbatim, as Rack sends it (Symfony would reorder the directives). */
    public static function setCacheControl(Response $response, string $value): void
    {
        if (!$response->headers instanceof RackResponseHeaderBag) {
            $response->headers = RackResponseHeaderBag::from($response->headers, $value);
        } else {
            $response->headers->set('Cache-Control', $value);
        }
    }

    /**
     * `generate_weak_etag(validators)`: `W/"<ActiveSupport::Digest.hexdigest(expand_cache_key(validators))>"`
     * (SHA256 truncated to 32 hex digits).
     *
     * @param list<string> $validators already expanded cache keys
     */
    public static function weakEtag(array $validators): string
    {
        return \sprintf('W/"%s"', substr(hash('sha256', implode('/', $validators)), 0, 32));
    }

    /** `record.cache_key_with_version`: "users/1-20260101120000000000" (`to_fs(:usec)`). */
    public static function cacheKeyWithVersion(string $table, int $id, \DateTimeInterface $updatedAt): string
    {
        $utc = \DateTimeImmutable::createFromInterface($updatedAt)->setTimezone(new \DateTimeZone('UTC'));

        return \sprintf('%s/%d-%s', $table, $id, $utc->format('YmdHisu'));
    }

    /** `Time#httpdate` */
    public static function httpdate(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
    }

    /**
     * `request.fresh?(response)`: every validator the request sent must match.
     */
    public static function isFresh(Request $request, ?string $etag, ?\DateTimeInterface $lastModified = null): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');
        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        $since = null;
        if (null !== $ifModifiedSince) {
            $since = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $ifModifiedSince, new \DateTimeZone('UTC')) ?: null;
        }
        if (null === $ifNoneMatch && null === $since) {
            return false;
        }
        $fresh = true;
        if (null !== $since) {
            $fresh = null !== $lastModified && $since >= $lastModified;
        }
        if (null !== $ifNoneMatch) {
            $validators = preg_split('/\s*,\s*/', $ifNoneMatch) ?: [];
            $fresh = $fresh && null !== $etag && (\in_array($etag, $validators, true) || \in_array('*', $validators, true));
        }

        return $fresh;
    }

    /**
     * `send_file path, type:, disposition:` (the filename is the file's basename).
     *
     * Streamed by PHP rather than offloaded to Caddy: Caddy's file_server would replace the
     * response's own ETag and Last-Modified (avatars, logos), which conditional GETs rely on.
     */
    public static function sendFile(Request $request, string $path, string $contentType, string $disposition = 'inline', ?string $filename = null): Response
    {
        $response = self::fileBody($request, $path);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', ContentDisposition::format($disposition, $filename ?? basename($path)));
        $response->headers->set('Content-Transfer-Encoding', 'binary');

        return $response;
    }

    /**
     * `send_stream(filename:, disposition:, type:)` of a blob's file: like sendFile, without
     * Content-Transfer-Encoding.
     */
    public static function streamFile(Request $request, string $path, ?string $contentType, string $disposition, string $filename): Response
    {
        $response = self::fileBody($request, $path);
        $response->headers->set('Content-Type', $contentType ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', ContentDisposition::format($disposition, $filename));

        return $response;
    }

    private static function fileBody(Request $request, string $path): StreamedResponse
    {
        $size = @filesize($path);
        if (false === $size) {
            throw new \App\Storage\FileNotFound($path);
        }
        $head = $request->isMethod('HEAD');
        $parts = 0 === $size ? [] : [[0, $size - 1]];

        return new StreamedResponse(static fn () => $head ? null : FileServer::emit($parts, $path), 200, ['Content-Length' => (string) $size]);
    }

    /**
     * `Rack::Utils.get_byte_ranges(http_range, size)`: null to ignore the header, [] when
     * unsatisfiable, else inclusive [start, end] pairs.
     *
     * @return list<array{int, int}>|null
     */
    public static function byteRanges(?string $httpRange, int $size, int $maxRanges = 100): ?array
    {
        if (0 === $size || null === $httpRange || 1 !== preg_match('/bytes=([^;]+)/', $httpRange, $m)) {
            return null;
        }
        $byteRange = $m[1];
        if (substr_count($byteRange, ',') >= $maxRanges) {
            return null;
        }
        $ranges = [];
        foreach (self::rubySplit('/,[ \t]*/', $byteRange) as $rangeSpec) {
            if (!str_contains($rangeSpec, '-')) {
                return null;
            }
            $parts = self::rubySplit('/-/', $rangeSpec);
            [$r0, $r1] = [$parts[0] ?? null, $parts[1] ?? null];
            if (null === $r0 || '' === $r0) {
                if (null === $r1) {
                    return null;
                }
                $r0 = max(0, $size - self::rubyToI($r1));
                $r1 = $size - 1;
            } else {
                $r0 = self::rubyToI($r0);
                if (null === $r1) {
                    $r1 = $size - 1;
                } else {
                    $r1 = self::rubyToI($r1);
                    if ($r1 < $r0) {
                        return null;
                    }
                    if ($r1 >= $size) {
                        $r1 = $size - 1;
                    }
                }
            }
            if ($r0 <= $r1) {
                $ranges[] = [$r0, $r1];
            }
        }
        $total = 0;
        foreach ($ranges as [$start, $end]) {
            $total += $end - $start + 1;
        }

        return $total > $size ? [] : $ranges;
    }

    /**
     * `String#split(pattern)`: trailing empty fields dropped.
     *
     * @return list<string>
     */
    private static function rubySplit(string $pattern, string $value): array
    {
        $parts = preg_split($pattern, $value) ?: [];
        while ([] !== $parts && '' === end($parts)) {
            array_pop($parts);
        }

        return $parts;
    }

    /**
     * Ruby's `String#to_i` (base 10): leading whitespace, a sign, an optional "0d" prefix, digits
     * with single underscores between them; 0 when nothing parses.
     */
    public static function rubyToI(string $value): int
    {
        if (1 !== preg_match('/\A[\t\n\v\f\r ]*([+-]?)(?:0[dD](?=\d))?(\d+(?:_\d+)*)/', $value, $m)) {
            return 0;
        }
        $digits = str_replace('_', '', $m[2]);
        if (\strlen(ltrim($digits, '0')) > 18) {
            return '-' === $m[1] ? \PHP_INT_MIN : \PHP_INT_MAX;
        }

        return (int) ($m[1].$digits);
    }
}
