<?php

declare(strict_types=1);

namespace App\Storage\Http;

use App\Entity\ActiveStorage\Blob;
use App\Storage\ContentTypes;
use App\Storage\DiskService;
use App\Storage\Filename;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** ActiveStorage::Streaming for the blob and representation proxies. */
final class BlobStreaming
{
    /**
     * `http_cache_forever(public: true) { send_blob_stream blob, disposition: }`, shared with
     * the representations proxy.
     */
    public static function sendForever(Request $request, DiskService $disk, Blob $blob, mixed $disposition, bool $withLength): Response
    {
        $etag = Responses::weakEtag([$request->getRequestUri()]);
        $lastModified = new \DateTimeImmutable('2011-01-01 00:00:00', new \DateTimeZone('UTC'));
        if (Responses::isFresh($request, $etag, $lastModified)) {
            $response = new Response('', 304);
        } else {
            $path = $disk->pathFor($blob->getKey());
            if (!is_file($path)) {
                // ActiveStorage::FileNotFoundError: expires_now; head :not_found
                $response = Responses::head($request, 404);
                Responses::expiresNow($response);

                return $response;
            }
            $response = Responses::streamFile(
                $request,
                $path,
                ContentTypes::forServing($blob->getContentType()),
                ContentTypes::forcedDisposition($blob->getContentType()) ?? (\is_string($disposition) ? $disposition : null) ?? 'inline',
                (new Filename($blob->getFilename()))->sanitized(),
            );
            if ($withLength) {
                $response->headers->set('Accept-Ranges', 'bytes');
                $response->headers->set('Content-Length', (string) $blob->getByteSize());
            }
        }
        Responses::expiresIn($response, Responses::HUNDRED_YEARS, public: true, immutable: true);
        $response->headers->set('ETag', $etag);
        $response->headers->set('Last-Modified', Responses::httpdate($lastModified));

        return $response;
    }
}
