<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\ActiveStorage\Blob;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `blob.url(disposition:)` for the disk service: an absolute, expiring
 * `/rails/active_storage/disk/:encoded_key/*filename` URL on the current request's host
 * (`ActiveStorage::Current.url_options`, set by ActiveStorage::SetCurrent).
 */
#[Autoconfigure(public: true)]
final readonly class ServiceUrls
{
    /** `ActiveStorage.service_urls_expire_in` (5 minutes) */
    public const int EXPIRES_IN = 300;

    public function __construct(
        private DiskService $disk,
        private ClockInterface $clock,
    ) {
    }

    /** `blob.url(expires_in: 5.minutes, disposition:)` */
    public function blobUrl(Request $request, Blob $blob, ?string $disposition = null): string
    {
        $path = $this->disk->urlPath(
            $blob->getKey(),
            $this->clock->now()->modify('+'.self::EXPIRES_IN.' seconds'),
            new Filename($blob->getFilename()),
            ContentTypes::forServing($blob->getContentType()),
            ContentTypes::forcedDisposition($blob->getContentType()) ?? $disposition,
        );

        return $request->getSchemeAndHttpHost().$path;
    }

    /** `redirect_to url, allow_other_host: true`: a 302 with an empty HTML body. */
    public static function redirect(string $url): Response
    {
        return new Response('', 302, ['Location' => $url, 'Content-Type' => 'text/html; charset=utf-8']);
    }
}
