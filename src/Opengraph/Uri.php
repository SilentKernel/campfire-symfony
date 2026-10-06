<?php

declare(strict_types=1);

namespace App\Opengraph;

/**
 * The parts of Ruby's `URI.parse` (RFC 3986 parser) link unfurling relies on: strict parsing
 * (null where Ruby raises URI::InvalidURIError), `is_a?(URI::HTTP)` (http and https), the
 * scheme's default port, and `to_s` after replacing the host.
 */
final readonly class Uri
{
    private const string PCT = '%[0-9A-Fa-f]{2}';
    private const string UNRESERVED_SUBDELIMS = "A-Za-z0-9\\-._~!$&'()*+,;=";

    private function __construct(
        public ?string $scheme,
        public ?string $userinfo,
        public ?string $host,
        public ?int $port,
        public string $path,
        public ?string $query,
        public ?string $fragment,
        private bool $hasAuthority,
    ) {
    }

    public static function parse(?string $uri): ?self
    {
        if (null === $uri || 1 !== preg_match('/\A[\x00-\x7F]*\z/', $uri)) {
            return null;
        }
        $pchar = '(?:'.self::PCT.'|['.self::UNRESERVED_SUBDELIMS.':@])';
        $regName = '(?:'.self::PCT.'|['.self::UNRESERVED_SUBDELIMS.'])*';
        $ipLiteral = '\[[0-9A-Fa-f:.vV\-._~!$&\'()*+,;=]+\]';
        $pattern = "\x01".'\A(?:(?<scheme>[A-Za-z][A-Za-z0-9+\-.]*):)?'
            .'(?://(?<authority>(?:(?<userinfo>(?:'.self::PCT.'|['.self::UNRESERVED_SUBDELIMS.':])*)@)?(?<host>'.$ipLiteral.'|'.$regName.')(?::(?<port>[0-9]*))?))?'
            .'(?<path>(?:'.$pchar.'|/)*)'
            .'(?:\?(?<query>[^#]*))?'
            .'(?:\#(?<fragment>(?:'.$pchar.'|[/?])*))?\z'."\x01";
        if (1 !== preg_match($pattern, $uri, $m, \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $hasAuthority = null !== ($m['authority'] ?? null);
        $path = (string) $m['path'];
        // A path after an authority must be empty or absolute; without a scheme, the first
        // segment may not contain ":".
        if ($hasAuthority && '' !== $path && !str_starts_with($path, '/')) {
            return null;
        }
        if (!$hasAuthority && str_starts_with($path, '//')) {
            return null;
        }
        $scheme = $m['scheme'] ?? null;
        if (null !== $scheme && 'mailto' === strtolower($scheme)) {
            self::checkMailto($path, $m['query'] ?? null);
        }
        $port = null !== ($m['port'] ?? null) && '' !== $m['port'] ? (int) $m['port'] : null;
        if (null === $port && null !== $scheme) {
            $port = match (strtolower($scheme)) {
                'http' => 80,
                'https' => 443,
                default => null,
            };
        }

        return new self($scheme, $m['userinfo'] ?? null, $hasAuthority ? (string) $m['host'] : null, $port, $path, $m['query'] ?? null, $m['fragment'] ?? null, $hasAuthority);
    }

    /**
     * URI::MailTo#initialize: the addresses must look like e-mail addresses, or Ruby raises
     * URI::InvalidComponentError (not the InvalidURIError callers rescue).
     */
    private static function checkMailto(string $to, ?string $query): void
    {
        $email = "[a-zA-Z0-9.!\\#$%&'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*";
        if ('' === $to && null !== $query) {
            return;
        }
        if (1 !== preg_match('/\A(?:'.$email.')(?:,(?:'.$email.'))*\z/', rawurldecode($to))) {
            throw new InvalidComponentError(\sprintf('unrecognised opaque part for mailtoURL: %s', $to));
        }
    }

    /** `is_a?(URI::HTTP)`: URI::HTTPS is a subclass. */
    public function isHttp(): bool
    {
        return null !== $this->scheme && \in_array(strtolower($this->scheme), ['http', 'https'], true);
    }

    public function isHttps(): bool
    {
        return null !== $this->scheme && 'https' === strtolower($this->scheme);
    }

    public function withHost(string $host): self
    {
        return new self($this->scheme, $this->userinfo, $host, $this->port, $this->path, $this->query, $this->fragment, true);
    }

    /** The host without IPv6 brackets (`URI#hostname`). */
    public function hostname(): ?string
    {
        return null === $this->host ? null : trim($this->host, '[]');
    }

    public function toString(): string
    {
        $uri = null !== $this->scheme ? $this->scheme.':' : '';
        if ($this->hasAuthority) {
            $uri .= '//'.(null !== $this->userinfo ? $this->userinfo.'@' : '').$this->host;
            if (null !== $this->port && $this->port !== $this->defaultPort()) {
                $uri .= ':'.$this->port;
            }
        }
        $uri .= $this->path;
        if (null !== $this->query) {
            $uri .= '?'.$this->query;
        }
        if (null !== $this->fragment) {
            $uri .= '#'.$this->fragment;
        }

        return $uri;
    }

    private function defaultPort(): ?int
    {
        return match (strtolower((string) $this->scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
