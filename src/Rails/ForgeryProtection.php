<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * ActionController::RequestForgeryProtection#verified_request? as configured in the reference app:
 * protect_from_forgery with: :exception (Authentication concern), per_form_csrf_tokens and
 * forgery_protection_origin_check on (load_defaults 5.0+), CSRF token in the session.
 *
 * The actionpack in the reference image (Rails 8.2.0.alpha, load_defaults 8.2) has no
 * Sec-Fetch-Site / header-only strategy: verification is origin check + token, nothing else.
 * $secFetchSite is accepted so callers pass the full request picture, and ignored.
 */
final class ForgeryProtection
{
    public static function verifiedRequest(
        string $method,
        string $path,
        ?string $origin,
        string $baseUrl,
        ?string $secFetchSite,
        ?string $headerToken,
        mixed $paramToken,
        ?string $sessionSecret,
        bool $originCheck = true,
    ): bool {
        $method = strtoupper($method);
        if ('GET' === $method || 'HEAD' === $method) {
            return true;
        }

        try {
            if ($originCheck && !self::validRequestOrigin($origin, $baseUrl)) {
                return false;
            }
        } catch (InvalidAuthenticityToken) {
            return false;
        }

        // Without a session secret Rails generates a fresh one, which no request token can match.
        if (null === $sessionSecret || null === Base64::urlsafeDecode($sessionSecret)) {
            return false;
        }

        // request_authenticity_tokens: [params[:authenticity_token], X-CSRF-Token header]
        foreach ([$paramToken, $headerToken] as $token) {
            if (CsrfToken::isValid($sessionSecret, $token, $path, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * valid_request_origin?: no Origin header, or exactly request.base_url.
     *
     * @throws InvalidAuthenticityToken for Origin "null", as Rails raises (also a 422)
     */
    public static function validRequestOrigin(?string $origin, string $baseUrl): bool
    {
        if ('null' === $origin) {
            throw new InvalidAuthenticityToken("The browser returned a 'null' origin for a request with origin-based forgery protection turned on.");
        }

        return null === $origin || $origin === $baseUrl;
    }
}
