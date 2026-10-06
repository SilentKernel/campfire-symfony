<?php

declare(strict_types=1);

namespace App\RichText;

/**
 * The slice of Ruby's `URI.parse` (uri 1.x, RFC 3986 parser) that the opengraph URL checks and
 * the tweet URL normalization rely on, including which inputs raise which error.
 *
 * parse() returns null where Ruby raises `URI::InvalidURIError` (which callers rescue) and throws
 * a RichTextError where it raises `URI::InvalidComponentError` (from `URI::MailTo`), which
 * nothing rescues.
 */
final class RubyUri
{
    public function __construct(
        public ?string $scheme = null,
        public ?string $userinfo = null,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $path = null,
        public ?string $opaque = null,
        public ?string $query = null,
        public ?string $fragment = null,
    ) {
    }

    /** @throws RichTextError URI::InvalidComponentError */
    public static function parse(string $value): ?self
    {
        if (1 === preg_match('/[\x80-\xFF]/', $value)) {
            return null;
        }
        $uri = self::splitAbsolute($value) ?? self::splitRelative($value);
        if (null === $uri) {
            return null;
        }
        // URI::Generic#initialize assigns the query through `query=`, which rejects bad escapes
        if (null !== $uri->query) {
            $query = self::escapeQuery($uri->query);
            if (null === $query) {
                return null;
            }
            $uri->query = $query;
        }
        $uri->port ??= $uri->defaultPort();
        if (!$uri->checkSchemeClass()) {
            return null;
        }

        return $uri;
    }

    /** `uri.is_a?(URI::HTTP)`, which includes `URI::HTTPS`. */
    public function isHttp(): bool
    {
        return \in_array(strtolower((string) $this->scheme), ['http', 'https'], true);
    }

    /** `URI::Generic#to_s` */
    public function toS(): string
    {
        $s = '';
        if (null !== $this->scheme) {
            $s .= $this->scheme.':';
        }
        if (null !== $this->opaque) {
            $s .= $this->opaque;
        } else {
            if (null !== $this->host || \in_array($this->scheme, ['file', 'postgres'], true)) {
                $s .= '//';
            }
            if (null !== $this->userinfo) {
                $s .= $this->userinfo.'@';
            }
            $s .= $this->host ?? '';
            if (null !== $this->port && $this->port !== $this->defaultPort()) {
                $s .= ':'.$this->port;
            }
            $s .= $this->path ?? '';
            if (null !== $this->query) {
                $s .= '?'.$this->query;
            }
        }
        if (null !== $this->fragment) {
            $s .= '#'.$this->fragment;
        }

        return $s;
    }

    private function defaultPort(): ?int
    {
        return match (strtoupper((string) $this->scheme)) {
            'HTTP', 'WS' => 80,
            'HTTPS', 'WSS' => 443,
            'FTP' => 21,
            'LDAP' => 389,
            'LDAPS' => 636,
            default => null,
        };
    }

    /**
     * The initializers of the scheme classes `URI.for` picks that can raise.
     *
     * @return bool false for URI::InvalidURIError
     *
     * @throws RichTextError URI::InvalidComponentError
     */
    private function checkSchemeClass(): bool
    {
        switch (strtoupper((string) $this->scheme)) {
            case 'MAILTO':
                $opaque = $this->opaque ?? (null !== $this->query ? '?'.$this->query : null);
                if (null === $opaque) {
                    throw new RichTextError('URI::InvalidComponentError');
                }
                $to = explode('?', $opaque, 2)[0];
                if (1 !== preg_match('/\A(?:[^@,;]+@[^@,;]+(?:\z|[,;]))*\z/', $to)) {
                    throw new RichTextError('URI::InvalidComponentError');
                }

                return true;
            case 'LDAP':
            case 'LDAPS':
                return null === $this->fragment && null !== $this->path;
            case 'FTP':
                return null !== $this->path;
            default:
                return true;
        }
    }

    /** `URI::Generic#query=`: rejects `%` followed by two non-hex characters and escapes the rest. */
    private static function escapeQuery(string $query): ?string
    {
        $cleaned = str_replace(["\t", "\r", "\n"], '', $query);
        if (1 === preg_match('/%[^0-9A-Fa-f][^0-9A-Fa-f]/', $cleaned)) {
            return null;
        }

        return preg_replace_callback('/%[0-9A-Fa-f]{2}|[^!$-&(-;=?-_a-~]/', static fn (array $m): string => '%' === $m[0][0] && \strlen($m[0]) > 1 ? $m[0] : \sprintf('%%%02X', \ord($m[0])), $cleaned) ?? $cleaned;
    }

    // --- RFC 3986 matching, mirroring URI::RFC3986_Parser::RFC3986_URI ---------------------

    private static function isUnreservedOrSub(string $b): bool
    {
        // [!$&-.0-9;=A-Z_a-z~]: `&-.` covers & ' ( ) * + , - .
        return 1 === preg_match('/[!$&-.0-9;=A-Z_a-z~]/', $b);
    }

    private static function pctAt(string $s, int $i): bool
    {
        return $i + 2 < \strlen($s) && '%' === $s[$i] && ctype_xdigit($s[$i + 1]) && ctype_xdigit($s[$i + 2]);
    }

    /** Consumes `(?:%\h\h|[class])*` possessively and returns the end index. */
    private static function takeWhile(string $s, int $i, callable $class, ?int $limit = null): int
    {
        $limit ??= \strlen($s);
        while (true) {
            if ($i + 2 < $limit && self::pctAt($s, $i)) {
                $i += 3;
            } elseif ($i < $limit && $class($s[$i])) {
                ++$i;
            } else {
                return $i;
            }
        }
    }

    private static function segChar(string $b): bool
    {
        return self::isUnreservedOrSub($b) || ':' === $b || '@' === $b || '/' === $b;
    }

    private static function segNcChar(string $b): bool
    {
        return self::isUnreservedOrSub($b) || '@' === $b;
    }

    private static function fragmentChar(string $b): bool
    {
        return self::isUnreservedOrSub($b) || ':' === $b || '@' === $b || '/' === $b || '?' === $b;
    }

    private static function userinfoChar(string $b): bool
    {
        return self::isUnreservedOrSub($b) || ':' === $b;
    }

    /** The IP-literal alternative of HOST (a bracketed address); returns its end. */
    private static function ipLiteral(string $s, int $i, int $limit): ?int
    {
        if ($i >= $limit || '[' !== $s[$i]) {
            return null;
        }
        $close = strpos($s, ']', $i);
        if (false === $close || $close >= $limit) {
            return null;
        }
        $inner = substr($s, $i + 1, $close - $i - 1);
        if ('' !== $inner && ('v' === $inner[0] || 'V' === $inner[0])) {
            $parts = explode('.', substr($inner, 1), 2);
            $valid = 2 === \count($parts) && '' !== $parts[0] && ctype_xdigit($parts[0]) && '' !== $parts[1]
                && 1 === preg_match('/\A[!$&-.0-9;=A-Z_a-z~:]+\z/', $parts[1]);
        } else {
            $valid = !str_contains($inner, '%') && false !== filter_var($inner, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
        }

        return $valid ? $close + 1 : null;
    }

    /** @return array{int, ?array{int, int}, ?array{int, int}}|null hier end, query and fragment spans */
    private static function tail(string $s, int $start): ?array
    {
        $length = \strlen($s);
        $hierEnd = $start;
        while ($hierEnd < $length && '?' !== $s[$hierEnd] && '#' !== $s[$hierEnd]) {
            ++$hierEnd;
        }
        $i = $hierEnd;
        $query = null;
        if ($i < $length && '?' === $s[$i]) {
            $end = strpos($s, '#', $i + 1);
            $end = false === $end ? $length : $end;
            $query = [$i + 1, $end];
            $i = $end;
        }
        $fragment = null;
        if ($i < $length && '#' === $s[$i]) {
            $end = self::takeWhile($s, $i + 1, self::fragmentChar(...));
            if ($end !== $length) {
                return null;
            }
            $fragment = [$i + 1, $length];
            $i = $length;
        }

        return $i === $length ? [$hierEnd, $query, $fragment] : null;
    }

    /** @return array{?string, string, ?int, int}|null userinfo, host, port, end */
    private static function authority(string $s, int $start, int $limit): ?array
    {
        $i = $start;
        $userinfo = null;
        $uiEnd = self::takeWhile($s, $i, self::userinfoChar(...), $limit);
        if ($uiEnd < $limit && '@' === $s[$uiEnd]) {
            $userinfo = substr($s, $i, $uiEnd - $i);
            $i = $uiEnd + 1;
        }
        $hostEnd = self::ipLiteral($s, $i, $limit) ?? self::takeWhile($s, $i, self::isUnreservedOrSub(...), $limit);
        $host = substr($s, $i, $hostEnd - $i);
        $end = $hostEnd;
        $port = null;
        if ($end < $limit && ':' === $s[$end]) {
            $digitsEnd = $end + 1;
            while ($digitsEnd < $limit && ctype_digit($s[$digitsEnd])) {
                ++$digitsEnd;
            }
            $digits = substr($s, $end + 1, $digitsEnd - $end - 1);
            $port = '' === $digits ? null : (\strlen(ltrim($digits, '0')) > 18 ? \PHP_INT_MAX : (int) $digits);
            $end = $digitsEnd;
        }
        if ($end < $limit && '/' !== $s[$end]) {
            return null;
        }

        return [$userinfo, $host, $port, $end];
    }

    private static function splitAbsolute(string $value): ?self
    {
        if (1 !== preg_match('/\A[A-Za-z][A-Za-z0-9+\-.]*:/', $value, $m)) {
            return null;
        }
        $restStart = \strlen($m[0]);
        $scheme = strtolower(substr($m[0], 0, -1));
        $tail = self::tail($value, $restStart);
        if (null === $tail) {
            return null;
        }
        [$hierEnd, $query, $fragment] = $tail;
        $hier = substr($value, $restStart, $hierEnd - $restStart);
        $uri = new self(
            scheme: $scheme,
            query: null !== $query ? substr($value, $query[0], $query[1] - $query[0]) : null,
            fragment: null !== $fragment ? substr($value, $fragment[0], $fragment[1] - $fragment[0]) : null,
        );
        if (str_starts_with($hier, '//')) {
            $authority = self::authority($value, $restStart + 2, $hierEnd);
            if (null === $authority) {
                return null;
            }
            [$uri->userinfo, $uri->host, $uri->port, $end] = $authority;
            $uri->path = substr($value, $end, $hierEnd - $end);
            if ('' !== $uri->path && ('/' !== $uri->path[0] || self::takeWhile($value, $end, self::segChar(...), $hierEnd) !== $hierEnd)) {
                return null;
            }
        } elseif (str_starts_with($hier, '/')) {
            if (self::takeWhile($value, $restStart, self::segChar(...), $hierEnd) !== $hierEnd) {
                return null;
            }
            $uri->path = $hier;
        } elseif ('' !== $hier) {
            // path-rootless becomes the opaque part, with the query folded back in
            if (self::takeWhile($value, $restStart, self::segChar(...), $hierEnd) !== $hierEnd) {
                return null;
            }
            $uri->opaque = $hier.(null !== $uri->query ? '?'.$uri->query : '');
            $uri->query = null;
        } else {
            $uri->path = '';
        }

        return $uri;
    }

    /** RFC3986_relative_ref: only validity matters, since a relative reference is never HTTP. */
    private static function splitRelative(string $value): ?self
    {
        $tail = self::tail($value, 0);
        if (null === $tail) {
            return null;
        }
        [$hierEnd, $query, $fragment] = $tail;
        $hier = substr($value, 0, $hierEnd);
        if (str_starts_with($hier, '//')) {
            $authority = self::authority($value, 2, $hierEnd);
            $valid = null !== $authority && self::takeWhile($value, $authority[3], self::segChar(...), $hierEnd) === $hierEnd;
        } elseif ('' === $hier || '/' === $hier[0]) {
            $valid = self::takeWhile($value, 0, self::segChar(...), $hierEnd) === $hierEnd;
        } else {
            $first = self::takeWhile($value, 0, self::segNcChar(...), $hierEnd);
            $valid = $first > 0 && ($first === $hierEnd || ('/' === $value[$first] && self::takeWhile($value, $first, self::segChar(...), $hierEnd) === $hierEnd));
        }
        if (!$valid) {
            return null;
        }

        return new self(
            path: $hier,
            query: null !== $query ? substr($value, $query[0], $query[1] - $query[0]) : null,
            fragment: null !== $fragment ? substr($value, $fragment[0], $fragment[1] - $fragment[0]) : null,
        );
    }
}
