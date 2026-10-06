<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage\Blobs;

use App\Entity\ActiveStorage\Blob;
use App\Http\Attribute\NotApplicationController;
use App\Storage\ContentDisposition;
use App\Storage\ContentTypes;
use App\Storage\DiskService;
use App\Storage\Filename;
use App\Storage\Http\BlobLookup;
use App\Storage\Http\BlobStreaming;
use App\Storage\Http\FileServer;
use App\Storage\Http\Responses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::Blobs::ProxyController: the blob streamed through the app, cached forever, with
 * byte ranges (ActiveStorage::Streaming). ActiveStorage::DisableSession: no session is loaded.
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class ProxyController extends AbstractController
{
    public function __construct(
        private readonly BlobLookup $lookup,
        private readonly DiskService $disk,
    ) {
    }

    #[Route('/rails/active_storage/blobs/proxy/{signed_id}/{filename}.{_format}', name: 'rails_service_blob_proxy', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 8)]
    public function show(Request $request): Response
    {
        $blob = $this->lookup->findSigned($request->attributes->get('signed_id'));
        if (null === $blob) {
            return Responses::head($request, 404, fromBeforeAction: true);
        }

        $range = $request->headers->get('Range');
        if (null !== $range && '' !== trim($range)) {
            return $this->sendByteRanges($request, $blob, $range);
        }

        return BlobStreaming::sendForever($request, $this->disk, $blob, $request->query->all()['disposition'] ?? null, withLength: true);
    }

    /** `send_blob_byte_range_data(blob, range_header)` */
    private function sendByteRanges(Request $request, Blob $blob, string $rangeHeader): Response
    {
        $ranges = Responses::byteRanges($rangeHeader, $blob->getByteSize());
        if (null === $ranges || [] === $ranges) {
            return Responses::head($request, 416);
        }
        $path = $this->disk->pathFor($blob->getKey());
        $size = $blob->getByteSize();
        $servingType = ContentTypes::forServing($blob->getContentType());

        if (1 === \count($ranges)) {
            [$start, $end] = $ranges[0];
            $contentType = $servingType;
            $parts = [[$start, $end]];
            $headers = ['Content-Range' => \sprintf('bytes %d-%d/%d', $start, $end, $size)];
        } else {
            $boundary = bin2hex(random_bytes(16));
            $contentType = 'multipart/byteranges; boundary='.$boundary;
            $parts = [];
            foreach ($ranges as [$start, $end]) {
                $parts[] = \sprintf("\r\n--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n", $boundary, $servingType, $start, $end, $size);
                $parts[] = [$start, $end];
            }
            $parts[] = \sprintf("\r\n--%s--\r\n", $boundary);
            $headers = [];
        }
        if (!is_file($path)) {
            throw new \App\Storage\FileNotFound($blob->getKey());
        }
        $length = 0;
        foreach ($parts as $part) {
            $length += \is_string($part) ? \strlen($part) : $part[1] - $part[0] + 1;
        }

        // send_data(data, disposition:, filename:, status: :partial_content, type:)
        $disposition = ContentTypes::forcedDisposition($blob->getContentType()) ?? 'inline';

        $head = $request->isMethod('HEAD');

        return new StreamedResponse(static fn () => $head ? null : FileServer::emit($parts, $path), 206, $headers + [
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Content-Type' => $contentType ?? 'application/octet-stream',
            'Content-Disposition' => ContentDisposition::format($disposition, (new Filename($blob->getFilename()))->sanitized()),
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }
}
