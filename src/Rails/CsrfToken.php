<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * Rails' authenticity tokens (actionpack/lib/action_controller/metal/request_forgery_protection.rb).
 *
 * The session stores a secret, session["_csrf_token"] = SecureRandom.urlsafe_base64(32). Its
 * decoded 32 bytes are the "real" token. Pages get it masked: a 32-byte one-time pad followed by
 * the pad XOR the token, URL-safe Base64 without padding. The meta tag carries the masked global
 * token, HMAC-SHA256(real, "!real_csrf_token"); with per_form_csrf_tokens (load_defaults 5.0+)
 * each button_to/form_with carries HMAC-SHA256(real, "<action path>#<method>").
 */
final class CsrfToken
{
    public const int LENGTH = 32;
    private const string GLOBAL_IDENTIFIER = '!real_csrf_token';

    /** generate_csrf_token: the value stored in session["_csrf_token"]. */
    public static function generateSecret(): string
    {
        return Base64::urlsafeEncode(random_bytes(self::LENGTH), false);
    }

    /** form_authenticity_token without form options: the masked global token (csrf_meta_tags). */
    public static function masked(string $secret): string
    {
        return self::mask(self::globalToken($secret));
    }

    /**
     * form_authenticity_token(form_options: { action:, method: }) with per-form tokens.
     * $actionPath must already be normalized (normalizeActionPath()).
     */
    public static function perForm(string $secret, string $actionPath, string $method): string
    {
        return self::mask(self::perFormToken($secret, $actionPath, $method));
    }

    /** global_csrf_token: raw 32 bytes. */
    public static function globalToken(string $secret): string
    {
        return hash_hmac('sha256', self::GLOBAL_IDENTIFIER, self::realToken($secret), true);
    }

    /** per_form_csrf_token: raw 32 bytes. */
    public static function perFormToken(string $secret, string $actionPath, string $method): string
    {
        return hash_hmac('sha256', $actionPath.'#'.strtolower($method), self::realToken($secret), true);
    }

    /** mask_token: a fresh one-time pad each call. */
    public static function mask(string $rawToken): string
    {
        $pad = random_bytes(self::LENGTH);

        return Base64::urlsafeEncode($pad.($pad ^ $rawToken), false);
    }

    /**
     * normalize_action_path: the path part of a form's action; a relative action ("messages",
     * "./x", "") is resolved against the page's path, with "/./" collapsed. Trailing "/" chomped.
     */
    public static function normalizeActionPath(string $action, string $pagePath): string
    {
        $path = self::uriPath($action);
        $relative = !preg_match('/\A[A-Za-z][A-Za-z0-9+\-.]*:/', $action);

        if ($relative && ('' === trim($action) || !str_starts_with($action, '/'))) {
            $path = str_replace('/./', '/', self::uriPath($pagePath).'/'.$path);
        }

        return self::chompSlash($path);
    }

    /**
     * valid_authenticity_token? for a request to $path with $method (real, global, or per-form
     * token for this path and method; masked or, for the real token, unmasked).
     */
    public static function isValid(string $secret, mixed $token, string $path, string $method): bool
    {
        if (!\is_string($token) || '' === $token) {
            return false;
        }
        $masked = Base64::urlsafeDecode($token);
        $real = Base64::urlsafeDecode($secret);
        if (null === $masked || null === $real) {
            return false;
        }

        if (self::LENGTH === \strlen($masked)) {
            return self::compare($masked, $real);
        }
        if (2 * self::LENGTH !== \strlen($masked)) {
            return false;
        }

        $token = substr($masked, 0, self::LENGTH) ^ substr($masked, self::LENGTH);

        return self::compare($token, self::globalToken($secret))
            || self::compare($token, $real)
            || self::compare($token, self::perFormToken($secret, self::chompSlash($path), $method));
    }

    private static function realToken(string $secret): string
    {
        return Base64::urlsafeDecode($secret) ?? throw new \InvalidArgumentException('The session CSRF secret is not valid Base64.');
    }

    /** fixed_length_secure_compare raises on a length mismatch, which never passes. */
    private static function compare(string $a, string $b): bool
    {
        return \strlen($a) === \strlen($b) && hash_equals($b, $a);
    }

    private static function chompSlash(string $path): string
    {
        return str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    }

    /** URI(...).path: no scheme, authority, query or fragment. */
    private static function uriPath(string $uri): string
    {
        $uri = preg_replace('/[?#].*\z/s', '', $uri) ?? $uri;
        $uri = preg_replace('/\A[A-Za-z][A-Za-z0-9+\-.]*:/', '', $uri) ?? $uri;
        if (str_starts_with($uri, '//')) {
            $slash = strpos($uri, '/', 2);

            return false === $slash ? '' : substr($uri, $slash);
        }

        return $uri;
    }
}
