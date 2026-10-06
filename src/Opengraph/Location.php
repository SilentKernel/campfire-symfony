<?php

declare(strict_types=1);

namespace App\Opengraph;

use Psr\Log\LoggerInterface;

/**
 * Opengraph::Location (reference/app/models/opengraph/location.rb): a URL that is valid when it
 * parses as http(s) and its host resolves to a public address, which is memoized and pinned for
 * the fetch.
 */
final class Location
{
    private const string FILES_AND_MEDIA_URL_REGEX = '~\bhttps?://\S+\.(?:zip|tar|tar\.gz|tar\.bz2|tar\.xz|gz|bz2|rar|7z|dmg|exe|msi|pkg|deb|iso|jpg|jpeg|png|gif|bmp|mp4|mov|avi|mkv|wmv|flv|heic|heif|mp3|wav|ogg|aac|wma|webm|ogv|mpg|mpeg)\b~';

    private ?Uri $parsedUrl;
    private bool $resolved = false;
    private ?string $resolvedIp = null;

    public function __construct(
        private readonly ?string $url,
        private readonly PrivateNetworkGuard $guard,
        private readonly Fetch $fetch,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // `URI.parse(url) rescue nil`
        try {
            $this->parsedUrl = Uri::parse($url);
        } catch (InvalidComponentError) {
            $this->parsedUrl = null;
        }
    }

    /** `valid?`: both validations run, so the host is resolved even for a non-http URL. */
    public function isValid(): bool
    {
        $http = null !== $this->parsedUrl && $this->parsedUrl->isHttp();
        $public = null !== $this->resolvedIp();

        return $http && $public;
    }

    /** `resolved_ip`, memoized. */
    public function resolvedIp(): ?string
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->resolvedIp = null !== $this->parsedUrl ? $this->guard->resolve($this->parsedUrl->host) : null;
        }

        return $this->resolvedIp;
    }

    /** `read_html`: nothing for invalid URLs or ones that look like files and media. */
    public function readHtml(): ?string
    {
        if (!$this->isValid() || 1 === preg_match(self::FILES_AND_MEDIA_URL_REGEX, (string) $this->url)) {
            return null;
        }
        \assert(null !== $this->parsedUrl && null !== $this->resolvedIp);

        try {
            return $this->fetch->fetchDocument($this->parsedUrl, $this->resolvedIp);
        } catch (\Throwable $error) {
            $this->logger?->warning(\sprintf('Failed to fetch %s at %s (%s)', $this->parsedUrl->toString(), $this->resolvedIp, $error->getMessage()));

            return null;
        }
    }

    /** `fetch_content_type` */
    public function fetchContentType(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }
        \assert(null !== $this->parsedUrl && null !== $this->resolvedIp);

        try {
            return $this->fetch->fetchContentType($this->parsedUrl, $this->resolvedIp);
        } catch (\Throwable $error) {
            $this->logger?->warning(\sprintf('Failed to fetch %s at %s (%s)', $this->parsedUrl->toString(), $this->resolvedIp, $error->getMessage()));

            return null;
        }
    }
}
