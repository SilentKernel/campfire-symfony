<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * cookies.signed[...] / cookies.encrypted[...] values and Rack's cookie wire format
 * (actionpack/lib/action_dispatch/middleware/cookies.rb, rack/utils.rb).
 *
 * Values work on the raw jar value; on the wire Rack escapes it (escape()) and unescapes incoming
 * cookies (unescape(), done by parseCookieHeader()). Per Rails 8.2 with load_defaults 8.2:
 * - the value is dumped with cookies_serializer :json (ActiveSupport::JSON),
 * - then signed (HMAC-SHA1 — signed_cookie_digest is unset — with key generate_key("signed
 *   cookie")) or encrypted (aes-256-gcm, key generate_key("authenticated encrypted cookie", 32)),
 *   in the legacy metadata envelope with pur "cookie.<name>" and exp (ISO 8601 ms, or null);
 * - reading tries purpose "cookie.<name>" first and then no purpose, so a value written without
 *   metadata (pre-5.2) is accepted under any name; Marshal-dumped values are refused.
 */
final class RailsCookies
{
    public const string SIGNED_COOKIE_SALT = 'signed cookie';
    public const string AUTHENTICATED_ENCRYPTED_COOKIE_SALT = 'authenticated encrypted cookie';

    private ?MessageVerifier $verifier = null;
    private ?MessageEncryptor $encryptor = null;

    public function __construct(private readonly KeyGenerator $keys)
    {
    }

    /** cookies.permanent: 20 calendar years from now (20.years.from_now). */
    public static function permanentExpiresAt(\DateTimeInterface $now): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($now)->modify('+20 years');
    }

    /** cookies.signed[name]; null wherever Rails returns nil. */
    public function readSigned(string $name, ?string $value, ?\DateTimeInterface $now = null): mixed
    {
        if (null === $value) {
            return null;
        }
        $verifier = $this->signedVerifier();

        return self::load($verifier->verified($value, self::purpose($name), $now))
            ?? self::load($verifier->verified($value, null, $now));
    }

    /** The raw value for cookies.signed[name] = { value:, expires: }. */
    public function writeSigned(string $name, mixed $value, ?\DateTimeInterface $expiresAt = null): string
    {
        return $this->signedVerifier()->generate(RailsJson::encode($value), self::purpose($name), $expiresAt);
    }

    /** cookies.encrypted[name]; null wherever Rails returns nil. */
    public function readEncrypted(string $name, ?string $value, ?\DateTimeInterface $now = null): mixed
    {
        if (null === $value) {
            return null;
        }
        $encryptor = $this->encryptor();

        return self::load($encryptor->decryptAndVerify($value, self::purpose($name), $now))
            ?? self::load($encryptor->decryptAndVerify($value, null, $now));
    }

    /** The raw value for cookies.encrypted[name] = { value:, expires: } (also the cookie session store). */
    public function writeEncrypted(string $name, mixed $value, ?\DateTimeInterface $expiresAt = null): string
    {
        return $this->encryptor()->encryptAndSign(RailsJson::encode($value), self::purpose($name), $expiresAt);
    }

    public function signedVerifier(): MessageVerifier
    {
        return $this->verifier ??= new MessageVerifier($this->keys->generateKey(self::SIGNED_COOKIE_SALT), 'sha1', serializer: Serializer::Null);
    }

    public function encryptor(): MessageEncryptor
    {
        return $this->encryptor ??= new MessageEncryptor($this->keys->generateKey(self::AUTHENTICATED_ENCRYPTED_COOKIE_SALT, 32), Serializer::Null);
    }

    /**
     * Rack::Utils.escape (URI.encode_www_form_component): "*-._" and alphanumerics stay, a space is
     * "+", everything else is %XX.
     */
    public static function escape(string $raw): string
    {
        return str_replace('%2A', '*', urlencode($raw));
    }

    /**
     * Rack's `unescape(value) rescue value`: "+" is a space, %XX is decoded, and a malformed
     * escape leaves the value untouched.
     */
    public static function unescape(string $wire): string
    {
        if (preg_match('/%(?![0-9a-fA-F]{2})/', $wire)) {
            return $wire;
        }

        return urldecode($wire);
    }

    /**
     * Rack::Utils.parse_cookies_header: the first occurrence of a name wins.
     *
     * @return array<string, string>
     */
    public static function parseCookieHeader(?string $header): array
    {
        $cookies = [];
        foreach (preg_split('/; */', $header ?? '') ?: [] as $cookie) {
            if ('' === $cookie) {
                continue;
            }
            [$key, $value] = explode('=', $cookie, 2) + [1 => null];
            if (!\array_key_exists($key, $cookies)) {
                $cookies[$key] = null === $value ? '' : self::unescape($value);
            }
        }

        return $cookies;
    }

    /**
     * Rack::Utils.set_cookie_header: "name=<escaped>; path=/; expires=<httpdate>; secure; httponly;
     * samesite=lax", attributes in Rack's order.
     */
    public static function setCookieHeader(string $name, string $value, ?\DateTimeInterface $expires, bool $httpOnly, ?string $sameSite, bool $secure, string $path = '/', ?string $domain = null, ?int $maxAge = null): string
    {
        if (!preg_match('/\A[!#$%&\'*+\-.^_`|~0-9a-zA-Z]+\z/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid cookie key: "%s".', $name));
        }

        $header = $name.'='.self::escape($value);
        if (null !== $domain) {
            $header .= '; domain='.$domain;
        }
        if ('' !== $path) {
            $header .= '; path='.$path;
        }
        if (null !== $maxAge) {
            $header .= '; max-age='.$maxAge;
        }
        if (null !== $expires) {
            $header .= '; expires='.\DateTimeImmutable::createFromInterface($expires)->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
        }
        if ($secure) {
            $header .= '; secure';
        }
        if ($httpOnly) {
            $header .= '; httponly';
        }
        if (null !== $sameSite) {
            $header .= '; samesite='.match (strtolower($sameSite)) {
                'lax' => 'lax',
                'strict' => 'strict',
                'none' => 'none',
                default => throw new \InvalidArgumentException(\sprintf('Invalid same_site value: "%s".', $sameSite)),
            };
        }

        return $header;
    }

    /**
     * What cookies.delete(name) sends: Rack's delete_set_cookie_header with the options Rails'
     * handle_options adds (path "/", same_site from cookies_same_site_protection, :lax).
     */
    public static function deleteCookieHeader(string $name, string $path = '/', ?string $domain = null, ?string $sameSite = 'lax'): string
    {
        return self::setCookieHeader($name, '', new \DateTimeImmutable('@0'), false, $sameSite, false, $path, $domain, 0);
    }

    private static function purpose(string $name): string
    {
        return 'cookie.'.$name;
    }

    /** SerializedCookieJars#parse with SerializerWithFallback[:json]: Marshal is refused. */
    private static function load(mixed $dumped): mixed
    {
        if (!\is_string($dumped)) {
            return null;
        }

        try {
            return Serializer::JsonWithFallback->load($dumped);
        } catch (InvalidMessage) {
            return null;
        }
    }
}
