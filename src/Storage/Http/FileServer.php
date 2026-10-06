<?php

declare(strict_types=1);

namespace App\Storage\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `ActiveStorage::FileServer#serve_file` over `Rack::Files#serving` (rack 3.2): OPTIONS, a
 * conditional GET on the exact mtime, single and multipart byte ranges, 416. The file's own type
 * is replaced by the signed content type and disposition. Whole files go out as a
 * BinaryFileResponse, which Caddy serves itself (X-Accel-Redirect) in production.
 */
final class FileServer
{
    public const string MULTIPART_BOUNDARY = 'AaB03x';

    private const int CHUNK = 1 << 20;

    public static function serve(Request $request, string $path, ?string $contentType, ?string $disposition): Response
    {
        $response = self::serving($request, $path);
        if (416 === $response->getStatusCode()) {
            $response->headers->remove('X-Cascade');
        }
        $response->headers->set('Content-Type', $contentType ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', $disposition ?? 'attachment');

        return $response;
    }

    private static function serving(Request $request, string $path): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            return new Response('', 200, ['Allow' => 'GET, HEAD, OPTIONS', 'Content-Length' => '0']);
        }
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if (false === $mtime || false === $size || !is_file($path)) {
            throw new \App\Storage\FileNotFound($path); // Errno::ENOENT
        }
        $lastModified = Responses::httpdate(new \DateTimeImmutable('@'.$mtime));
        if ($request->headers->get('If-Modified-Since') === $lastModified) {
            return new Response('', 304);
        }

        // Disk keys have no extension: Rack's mime lookup gives its default, text/plain.
        $mimeType = 'text/plain';
        $ranges = Responses::byteRanges($request->headers->get('Range'), $size);
        if (null === $ranges) {
            BinaryFileResponse::trustXSendfileTypeHeader();
            $response = new BinaryFileResponse($path, 200, [], false, null, false, false);
            $response->headers->set('Last-Modified', $lastModified);
            $response->headers->set('Content-Type', $mimeType);
            $response->headers->set('Content-Length', (string) $size);

            return $response;
        }
        if ([] === $ranges) {
            $body = "Byte range unsatisfiable\n";

            return new Response($body, 416, [
                'Content-Type' => 'text/plain',
                'Content-Length' => (string) \strlen($body),
                'X-Cascade' => 'pass',
                'Content-Range' => 'bytes */'.$size,
            ]);
        }

        $headers = ['Last-Modified' => $lastModified, 'Content-Type' => $mimeType];
        $parts = [];
        if (1 === \count($ranges)) {
            [$start, $end] = $ranges[0];
            $headers['Content-Range'] = \sprintf('bytes %d-%d/%d', $start, $end, $size);
            $parts[] = [$start, $end];
        } else {
            $headers['Content-Type'] = 'multipart/byteranges; boundary='.self::MULTIPART_BOUNDARY;
            foreach ($ranges as [$start, $end]) {
                $parts[] = \sprintf("\r\n--%s\r\ncontent-type: %s\r\ncontent-range: bytes %d-%d/%d\r\n\r\n", self::MULTIPART_BOUNDARY, $mimeType, $start, $end, $size);
                $parts[] = [$start, $end];
            }
            $parts[] = \sprintf("\r\n--%s--\r\n", self::MULTIPART_BOUNDARY);
        }
        $length = 0;
        foreach ($parts as $part) {
            $length += \is_string($part) ? \strlen($part) : $part[1] - $part[0] + 1;
        }
        $headers['Content-Length'] = (string) $length;

        $head = 'HEAD' === $request->getMethod();

        return new StreamedResponse(static function () use ($parts, $path, $head): void {
            if ($head) {
                return;
            }
            self::emit($parts, $path);
        }, 206, $headers);
    }

    /**
     * Writes the parts: literal strings, or inclusive byte ranges of the file.
     *
     * @param list<string|array{int, int}> $parts
     */
    public static function emit(array $parts, string $path): void
    {
        $file = fopen($path, 'r');
        if (false === $file) {
            return;
        }
        try {
            foreach ($parts as $part) {
                if (\is_string($part)) {
                    echo $part;
                    continue;
                }
                [$start, $end] = $part;
                fseek($file, $start);
                $remaining = $end - $start + 1;
                while ($remaining > 0 && !feof($file)) {
                    $chunk = fread($file, min(self::CHUNK, $remaining));
                    if (false === $chunk || '' === $chunk) {
                        break;
                    }
                    echo $chunk;
                    $remaining -= \strlen($chunk);
                }
                flush();
            }
        } finally {
            fclose($file);
        }
    }
}
