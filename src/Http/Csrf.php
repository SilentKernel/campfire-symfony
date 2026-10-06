<?php

declare(strict_types=1);

namespace App\Http;

use App\Rails\CsrfToken;
use App\Rails\ForgeryProtection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ActionController::RequestForgeryProtection with Campfire's settings (load_defaults 8.2:
 * per-form tokens, origin check, the secret in `session[:_csrf_token]`).
 */
final readonly class Csrf
{
    public const string PARAM = 'authenticity_token';
    public const string SESSION_KEY = '_csrf_token';

    public function __construct(
        private RailsSession $session,
        private RequestStack $requestStack,
    ) {
    }

    /** `request_forgery_protection_token` */
    public function param(): string
    {
        return self::PARAM;
    }

    /** `form_authenticity_token`: the masked global token (csrf-token meta tag). */
    public function maskedToken(): string
    {
        return CsrfToken::masked($this->secret());
    }

    /** `form_authenticity_token(form_options: { action:, method: })`: a masked per-form token. */
    public function formToken(string $action, string $method): string
    {
        $path = $this->requestStack->getMainRequest()?->getPathInfo() ?? '/';

        return CsrfToken::perForm($this->secret(), CsrfToken::normalizeActionPath($action, $path), $method);
    }

    /**
     * `verified_request?`: GET/HEAD, or a matching Origin and a valid token from
     * `params[:authenticity_token]` or X-CSRF-Token. False means InvalidAuthenticityToken (422).
     */
    public function verify(Request $request): bool
    {
        $method = $request->getMethod();
        if ('GET' === $method || 'HEAD' === $method) {
            return true;
        }

        return ForgeryProtection::verifiedRequest(
            $method,
            $request->getPathInfo(),
            $request->headers->get('Origin'),
            $request->getSchemeAndHttpHost(),
            $request->headers->get('Sec-Fetch-Site'),
            $request->headers->get('X-CSRF-Token'),
            Params::fromRequest($request)->get(self::PARAM),
            // real_csrf_token: generated and stored on first use, as Rails does.
            $this->secret(),
        );
    }

    /** `session[:_csrf_token] ||= generate_csrf_token` */
    public function secret(): string
    {
        $secret = $this->session->get(self::SESSION_KEY);
        if (!\is_string($secret) || '' === $secret) {
            $secret = CsrfToken::generateSecret();
            $this->session->set(self::SESSION_KEY, $secret);
        }

        return $secret;
    }
}
