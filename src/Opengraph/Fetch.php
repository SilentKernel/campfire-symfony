<?php

declare(strict_types=1);

namespace App\Opengraph;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Opengraph::Fetch (reference/app/models/opengraph/fetch.rb): a GET or HEAD pinned to the
 * address the guard resolved, following at most 10 responses, where every 3xx is a redirect
 * whose Location must be an absolute http(s) URL and is resolved through the guard again. A
 * document must be a 200 `text/html` of at most 5MB, by Content-Length and by what is read.
 *
 * Requests go through NoPrivateNetworkHttpClient, which checks the pinned address once more
 * and the address actually connected to. Unlike Rails (60s per connect and read), each request
 * gets 5s of inactivity and 10s in all.
 *
 * Errors raise Opengraph\FetchError; Location rescues them like Rails' `rescue => e`.
 */
final readonly class Fetch
{
    public const string ALLOWED_DOCUMENT_CONTENT_TYPE = 'text/html';
    public const int MAX_BODY_SIZE = 5 * 1024 * 1024;
    public const int MAX_REDIRECTS = 10;

    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client, private PrivateNetworkGuard $guard)
    {
        $this->client = new NoPrivateNetworkHttpClient($client, PrivateNetworkGuard::BLOCKED_SUBNETS);
    }

    /** `fetch_document(url, ip:)`: the body, or null when the response is not acceptable. */
    public function fetchDocument(Uri $url, string $ip): ?string
    {
        $response = $this->request($url, 'GET', $ip);
        if (!$this->isValid($response)) {
            $response->cancel();

            return null;
        }

        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (\strlen($body) + \strlen($content) > self::MAX_BODY_SIZE) {
                $response->cancel();

                return null;
            }
            $body .= $content;
        }

        return $body;
    }

    /** `fetch_content_type(url, ip:)`: the final response's Content-Type, whatever its status. */
    public function fetchContentType(Uri $url, string $ip): ?string
    {
        $response = $this->request($url, 'HEAD', $ip);
        $headers = $response->getHeaders(false);

        return $headers['content-type'][0] ?? null;
    }

    private function request(Uri $url, string $method, string $ip): ResponseInterface
    {
        for ($i = 0; $i < self::MAX_REDIRECTS; ++$i) {
            $response = $this->send($url, $method, $ip);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();
                [$url, $ip] = $this->resolveRedirect($location);
            } else {
                return $response;
            }
        }

        throw new FetchError('Opengraph::Fetch::TooManyRedirectsError');
    }

    /** @return array{Uri, string} */
    private function resolveRedirect(?string $location): array
    {
        try {
            $url = Uri::parse($location);
        } catch (InvalidComponentError $error) {
            throw new FetchError($error->getMessage(), 0, $error);
        }
        $url ??= throw new FetchError('URI::InvalidURIError');
        if (!$url->isHttp()) {
            throw new FetchError('Opengraph::Fetch::RedirectDeniedError');
        }
        $ip = $this->guard->resolve($url->host) ?? throw new FetchError('RestrictedHTTP::Violation');

        return [$url, $ip];
    }

    private function send(Uri $url, string $method, string $ip): ResponseInterface
    {
        $host = $url->hostname();
        if (null === $host || '' === $host || null === $url->port) {
            throw new FetchError('URI::InvalidURIError');
        }

        try {
            return $this->client->request($method, $url->toString(), [
                'max_redirects' => 0,
                'resolve' => [$host => $ip],
                'timeout' => 5,
                'max_duration' => 10,
                // Net::HTTP's defaults.
                'headers' => ['Accept' => '*/*', 'User-Agent' => 'Ruby'],
            ]);
        } catch (\Throwable $error) {
            throw new FetchError($error->getMessage(), 0, $error);
        }
    }

    private function isValid(ResponseInterface $response): bool
    {
        $headers = $response->getHeaders(false);

        return 200 === $response->getStatusCode()
            && self::ALLOWED_DOCUMENT_CONTENT_TYPE === self::contentType($headers['content-type'][0] ?? null)
            && self::contentLength($headers['content-length'][0] ?? null) <= self::MAX_BODY_SIZE;
    }

    /** `Net::HTTPHeader#content_type`: "main/sub" of the media type, each stripped (case kept). */
    public static function contentType(?string $header): ?string
    {
        if (null === $header) {
            return null;
        }
        $parts = explode('/', explode(';', $header, 2)[0]);
        $main = trim($parts[0], " \t\n\v\f\r\0");

        return isset($parts[1]) ? $main.'/'.trim($parts[1], " \t\n\v\f\r\0") : $main;
    }

    /** `Net::HTTPHeader#content_length`: the first run of digits; none is HTTPHeaderSyntaxError. */
    public static function contentLength(?string $header): int
    {
        if (null === $header) {
            return 0;
        }
        if (1 !== preg_match('/\d+/', $header, $match)) {
            throw new FetchError('wrong Content-Length format');
        }

        return \strlen(ltrim($match[0], '0')) > 18 ? \PHP_INT_MAX : (int) $match[0];
    }
}
