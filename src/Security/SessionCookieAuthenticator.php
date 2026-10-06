<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Session;
use App\Http\Cookies;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * `restore_authentication`: the signed `session_token` cookie names a Session row
 * (`Session.find_by(token:)`), which is resumed. Runs only when the pipeline asks for it
 * (Authentication::MODE_ATTRIBUTE); a missing, forged or stale cookie is simply no session.
 */
final class SessionCookieAuthenticator extends AbstractAuthenticator
{
    private const string SESSION_ATTRIBUTE = '_campfire_session_record';

    public function __construct(
        private readonly Authentication $authentication,
        private readonly Cookies $cookies,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$this->cookies->has(Authentication::COOKIE)) {
            return false;
        }

        // Lazy until the pipeline asks (both require and restore try the cookie).
        return null === Authentication::mode($request) ? null : true;
    }

    public function authenticate(Request $request): Passport
    {
        $session = $this->authentication->findSessionByCookie() ?? throw new BadCredentialsException('No session for the session_token cookie.');
        $request->attributes->set(self::SESSION_ATTRIBUTE, $session);

        return new SelfValidatingPassport(new UserBadge((string) $session->getUser()->getId(), static fn () => $session->getUser()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $session = $request->attributes->get(self::SESSION_ATTRIBUTE);
        $request->attributes->remove(self::SESSION_ATTRIBUTE);
        if ($session instanceof Session) {
            $this->authentication->resumeSession($session, $request);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
