<?php

declare(strict_types=1);

namespace App\Cable\Server;

/**
 * The HTTP request that opens a cable connection, and the answers to it
 * (ActionCable::Connection::Base#process, websocket-driver's Hybi handshake).
 */
final readonly class Handshake
{
    /** Offered in Sec-WebSocket-Protocol (ActionCable::INTERNAL[:protocols]). */
    public const array PROTOCOLS = ['actioncable-v1-json', 'actioncable-unsupported'];

    public const int MAX_HEADER_BYTES = 16384;

    /** @param array<string, string> $headers lower-cased names; repeated headers joined with ", " */
    public function __construct(
        public string $method,
        public string $target,
        public array $headers,
    ) {
    }

    /**
     * Parses the request head (up to and including the blank line).
     */
    public static function parse(string $head): ?self
    {
        $lines = explode("\r\n", rtrim($head, "\r\n"));
        $requestLine = array_shift($lines);
        if (null === $requestLine || !preg_match('~^([A-Z]+) (\S+) HTTP/1\.[01]$~', $requestLine, $m)) {
            return null;
        }
        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                return null;
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1), " \t");
            $headers[$name] = isset($headers[$name]) ? $headers[$name].('cookie' === $name ? '; ' : ', ').$value : $value;
        }

        return new self($m[1], $m[2], $headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** WebSocket::Driver.websocket?(env): a GET with Connection: upgrade and Upgrade: websocket. */
    public function isWebSocket(): bool
    {
        $connection = array_map(trim(...), explode(',', strtolower($this->header('connection') ?? '')));

        return 'GET' === $this->method
            && \in_array('upgrade', $connection, true)
            && 'websocket' === strtolower($this->header('upgrade') ?? '');
    }

    /**
     * Connection::Base#allow_request_origin? with the production defaults: same origin as the
     * Host header (`allow_same_origin_as_host`), with https when AssumeSSL applies or the proxy
     * says so (Rack::Request#ssl?), or one matching $allowedOrigins (regular expressions, as
     * `allowed_request_origins` takes; development allows localhost on any port).
     *
     * @param list<string> $allowedOrigins
     */
    public function allowsOrigin(bool $assumeSsl, array $allowedOrigins = [], bool $disableForgeryProtection = false): bool
    {
        if ($disableForgeryProtection) {
            return true;
        }
        $origin = $this->header('origin');
        $proto = $assumeSsl || $this->forwardedSsl() ? 'https' : 'http';
        if (null !== $origin && $origin === $proto.'://'.($this->header('host') ?? '')) {
            return true;
        }

        foreach ($allowedOrigins as $pattern) {
            if (null !== $origin && 1 === preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /** The first protocol in the client's list that Action Cable speaks (websocket-driver order). */
    public function protocol(): ?string
    {
        foreach (explode(',', $this->header('sec-websocket-protocol') ?? '') as $requested) {
            if (\in_array(trim($requested), self::PROTOCOLS, true)) {
                return trim($requested);
            }
        }

        return null;
    }

    /** The 101 response, or null when the request lacks a valid key or version. */
    public function accept(): ?string
    {
        $key = $this->header('sec-websocket-key');
        if (null === $key || '13' !== $this->header('sec-websocket-version') || 16 !== \strlen((string) base64_decode($key, true))) {
            return null;
        }
        $response = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".WebSocket::acceptKey($key)."\r\n";
        if (null !== $protocol = $this->protocol()) {
            $response .= 'Sec-WebSocket-Protocol: '.$protocol."\r\n";
        }

        return $response."\r\n";
    }

    /** Connection::Base#respond_to_invalid_request. */
    public static function pageNotFound(): string
    {
        return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: 14\r\nConnection: close\r\n\r\nPage not found";
    }

    public static function badRequest(): string
    {
        return "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: 11\r\nConnection: close\r\n\r\nBad Request";
    }

    private function forwardedSsl(): bool
    {
        $first = fn (string $name): string => strtolower(trim(explode(',', $this->header($name) ?? '')[0]));

        return 'on' === $first('x-forwarded-ssl')
            || 'https' === $first('x-forwarded-scheme')
            || 'https' === $first('x-forwarded-proto');
    }
}
