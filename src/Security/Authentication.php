<?php

declare(strict_types=1);

namespace App\Security;

use App\Cable\Broadcaster;
use App\Entity\Session;
use App\Entity\User;
use App\Http\Cookies;
use App\Http\Current;
use App\Http\RailsSession;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Campfire's Authentication concern (reference/app/controllers/concerns/authentication.rb and
 * authentication/session_lookup.rb, reference/app/models/session.rb).
 *
 * The lookups themselves run as Symfony Security authenticators on the lazy "main" firewall:
 * requireAuthentication()/restoreAuthentication() say which ones may run (request attribute
 * MODE_ATTRIBUTE) and then initialize the token storage, which runs them.
 */
final readonly class Authentication
{
    public const string MODE_ATTRIBUTE = '_campfire_authentication';
    public const string MODE_RESTORE = 'restore';
    public const string MODE_REQUIRE = 'require';

    public const string COOKIE = 'session_token';
    public const string RETURN_TO = 'return_to_after_authenticating';

    public function __construct(
        private Current $current,
        private Cookies $cookies,
        private RailsSession $session,
        private SessionRepository $sessions,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urls,
        private RequestStack $requestStack,
        private Broadcaster $broadcaster,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * `require_authentication`: `restore_authentication || bot_authentication ||
     * request_authentication`. Null when signed in, else the redirect to sign in.
     */
    public function requireAuthentication(Request $request): ?Response
    {
        $this->authenticate($request, self::MODE_REQUIRE);

        return $this->current->isSignedIn() ? null : $this->requestAuthentication($request);
    }

    /** `restore_authentication`: resume the session named by the session_token cookie. */
    public function restoreAuthentication(Request $request): bool
    {
        $this->authenticate($request, self::MODE_RESTORE);

        return null !== $this->current->session();
    }

    /** `request_authentication` */
    public function requestAuthentication(Request $request): Response
    {
        $this->session->set(self::RETURN_TO, $request->getSchemeAndHttpHost().$request->getRequestUri());

        return self::redirect($this->urls->generate('new_session', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** `redirect_signed_in_user_to_root` */
    public function redirectSignedInUserToRoot(): ?Response
    {
        return $this->current->isSignedIn() ? self::redirect($this->urls->generate('root', [], UrlGeneratorInterface::ABSOLUTE_URL)) : null;
    }

    /** `find_session_by_cookie` (Authentication::SessionLookup). */
    public function findSessionByCookie(): ?Session
    {
        $token = $this->cookies->signed(self::COOKIE);

        return \is_string($token) ? $this->sessions->findOneByToken($token) : null;
    }

    /** `resume_session`: refresh the activity at most hourly, then authenticate as the session. */
    public function resumeSession(Session $session, Request $request): void
    {
        $now = $this->clock->now();
        if ($session->isActivityStale($now)) {
            $session->setUserAgent($request->headers->get('User-Agent'));
            $session->setIpAddress($request->getClientIp());
            $session->setLastActiveAt($now);
            $this->em->flush();
        }

        $this->authenticatedAs($session);
    }

    /** `start_new_session_for(user)`: `user.sessions.start!` and authenticate as it. */
    public function startNewSessionFor(User $user, ?Request $request = null): Session
    {
        $request ??= $this->requestStack->getCurrentRequest();
        $session = new Session(
            $user,
            SecureToken::generate(),
            $this->clock->now(),
            $request?->headers->get('User-Agent'),
            $request?->getClientIp(),
        );
        $this->em->persist($session);
        $this->em->flush();

        $this->authenticatedAs($session);

        return $session;
    }

    /**
     * `terminate_current_session`: destroy the session record, reset the Rails session, delete
     * the cookie and tell the user's sockets to reconnect.
     */
    public function terminateCurrentSession(): void
    {
        if (null !== $session = $this->current->session()) {
            $this->em->remove($session);
            $this->em->flush();
        }
        $this->session->resetSession();
        $this->cookies->delete(self::COOKIE);

        if (null !== $user = $this->current->user()) {
            try {
                $this->broadcaster->disconnectUser($user->getId(), reconnect: true);
            } catch (\Throwable $error) {
                $this->logger->warning(\sprintf('Could not disconnect remote connections on sign out: %s', $error::class));
            }
        }
    }

    /** `post_authenticating_url`: `session.delete(:return_to_after_authenticating) || root_url`. */
    public function postAuthenticatingUrl(): string
    {
        $url = $this->session->delete(self::RETURN_TO);

        return \is_string($url) && '' !== $url ? $url : $this->urls->generate('root', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * `authenticated_as(session)`: Current.session (and user), authenticated by session, and the
     * permanent signed session_token cookie, re-set on every authenticated request.
     */
    public function authenticatedAs(Session $session): void
    {
        $this->current->setSession($session);
        $this->current->setAuthenticatedBy(Current::BY_SESSION);
        $this->cookies->setSigned(self::COOKIE, $session->getToken(), ['permanent' => true, 'httponly' => true, 'same_site' => 'lax']);
    }

    /** `bot_authentication` succeeded. */
    public function authenticatedAsBot(User $bot): void
    {
        $this->current->setUser($bot);
        $this->current->setAuthenticatedBy(Current::BY_BOT_KEY);
    }

    /** The mode the pipeline asked for on this request, if any. */
    public static function mode(Request $request): ?string
    {
        $mode = $request->attributes->get(self::MODE_ATTRIBUTE);

        return \is_string($mode) ? $mode : null;
    }

    private function authenticate(Request $request, string $mode): void
    {
        $request->attributes->set(self::MODE_ATTRIBUTE, $mode);
        // Runs the lazy firewall's authenticators (once per request).
        $this->tokenStorage->getToken();
    }

    /** `redirect_to url` (302, empty body). */
    private static function redirect(string $url): Response
    {
        return new Response('', Response::HTTP_FOUND, ['Location' => $url, 'Content-Type' => 'text/html; charset=utf-8']);
    }
}
