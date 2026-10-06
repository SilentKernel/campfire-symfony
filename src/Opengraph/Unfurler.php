<?php

declare(strict_types=1);

namespace App\Opengraph;

use Psr\Log\LoggerInterface;

/**
 * `Opengraph::Metadata.from_url(url)` and `valid?` (reference/app/models/opengraph/metadata.rb,
 * metadata/fetching.rb), for UnfurlLinksController: the JSON to render, or null for 204.
 * Tweets are read through fxtwitter.com, which serves OpenGraph tags.
 */
final readonly class Unfurler
{
    public const array TWITTER_HOSTS = ['twitter.com', 'www.twitter.com', 'x.com', 'www.x.com'];
    public const string FX_TWITTER_HOST = 'fxtwitter.com';
    public const array ALLOWED_IMAGE_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private PrivateNetworkGuard $guard,
        private Fetch $fetch,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** The response body of `render json: opengraph`, or null for `head :no_content`. */
    public function unfurl(string $url): ?string
    {
        $metadata = $this->fromUrl($url);

        return $metadata->isValid(fn (string $image): bool => $this->location($image)->isValid()) ? $metadata->toJson() : null;
    }

    /** `Metadata.from_url(url)` */
    public function fromUrl(string $url): Metadata
    {
        $attributes = Document::opengraphAttributes($this->fetchDocument($url));
        $attributes['url'] = $this->validCanonicalUrl($attributes['url'] ?? null, $url);
        $attributes['image'] = $this->validImageContentType($attributes['image'] ?? null);

        return new Metadata($attributes);
    }

    public function location(?string $url): Location
    {
        return new Location($url, $this->guard, $this->fetch, $this->logger);
    }

    private function fetchDocument(string $untrustedUrl): ?string
    {
        if (self::isTweetUrl($untrustedUrl)) {
            $html = $this->location(self::replaceTwitterDomain($untrustedUrl))->readHtml();

            // `location.read_html.force_encoding("UTF-8")` raises NoMethodError on nil.
            return $html ?? throw new \RuntimeException("undefined method 'force_encoding' for nil");
        }

        return $this->location($untrustedUrl)->readHtml();
    }

    private function validCanonicalUrl(?string $url, string $fallback): string
    {
        return null !== $url && $this->location($url)->isValid() ? $url : $fallback;
    }

    private function validImageContentType(?string $image): ?string
    {
        if (Document::isBlank($image)) {
            return null;
        }
        try {
            $parsed = Uri::parse($image);
        } catch (InvalidComponentError) {
            $parsed = null;
        }
        if (null === $parsed) {
            $this->logger?->warning(\sprintf('Failed to fetch image content tpye: %s (bad URI)', $image));

            return null;
        }
        $contentType = $this->location($image)->fetchContentType();

        return null !== $contentType && \in_array(strtolower($contentType), self::ALLOWED_IMAGE_CONTENT_TYPES, true) ? $image : null;
    }

    /** `tweet_url?`: URI::InvalidComponentError (a bad mailto: URL) is not rescued. */
    public static function isTweetUrl(string $url): bool
    {
        $uri = Uri::parse($url);

        return null !== $uri && \in_array($uri->host, self::TWITTER_HOSTS, true) && !Document::isBlank($uri->path) && '/' !== $uri->path;
    }

    /** `replace_twitter_domain_for_opengraph_support` */
    public static function replaceTwitterDomain(string $url): ?string
    {
        try {
            $uri = Uri::parse($url);
        } catch (InvalidComponentError) {
            return null;
        }
        if (null === $uri) {
            return null;
        }

        return (\in_array($uri->host, self::TWITTER_HOSTS, true) ? $uri->withHost(self::FX_TWITTER_HOST) : $uri)->toString();
    }
}
