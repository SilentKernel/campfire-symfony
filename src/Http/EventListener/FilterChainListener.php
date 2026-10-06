<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use App\Controller\ApplicationController;
use App\Http\Attribute\ActionNotFound;
use App\Http\Attribute\AllowBotAccess;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Attribute\NotApplicationController;
use App\Http\Attribute\RequireUnauthenticatedAccess;
use App\Http\Attribute\SkipForgeryProtection;
use App\Http\BrowserPolicy;
use App\Http\Csrf;
use App\Http\Current;
use App\Http\Pipeline;
use App\Rails\InvalidAuthenticityToken;
use App\Repository\BanRepository;
use App\Security\Authentication;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ApplicationController's before_action chain, in the order Rails runs it (the concerns'
 * `included` hooks run right to left, verified with a callback dump of the reference):
 *
 * 1. set_version_headers (VersionHeaders)
 * 2. Current.request = request (SetCurrentRequest)
 * 3. reject_banned_ip unless GET/HEAD (BlockBannedRequests): 429
 * 4. require_authentication, unless #[AllowUnauthenticatedAccess]/#[RequireUnauthenticatedAccess]
 * 5. deny_bots, unless #[AllowBotAccess]: 403 for bot-key requests
 * 6. verify_authenticity_token, unless authenticated by bot key or #[SkipForgeryProtection]: 422
 * 7. allow_browser (BrowserPolicy)
 * 8. #[RequireUnauthenticatedAccess]: restore_authentication, redirect_signed_in_user_to_root
 *
 * Controllers outside ApplicationController (#[NotApplicationController]) get only the
 * framework-wide `protect_from_forgery with: :exception`; #[ActionNotFound] actions get nothing.
 * A filter that responds replaces the controller (Pipeline::HALTED).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -8)]
final readonly class FilterChainListener
{
    public function __construct(
        private Current $current,
        private Csrf $csrf,
        private Authentication $authentication,
        private BanRepository $bans,
        private BrowserPolicy $browserPolicy,
        #[Autowire('%env(APP_VERSION)%')] private string $appVersion,
        #[Autowire('%env(GIT_REVISION)%')] private string $gitRevision,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $class = \is_array($controller) && \is_object($controller[0]) ? $controller[0]::class : (\is_object($controller) && !$controller instanceof \Closure ? $controller::class : null);
        if (null === $class || !str_starts_with($class, 'App\\Controller\\') || [] !== $event->getAttributes(ActionNotFound::class)) {
            return;
        }

        $request = $event->getRequest();
        $skipForgery = [] !== $event->getAttributes(SkipForgeryProtection::class);

        if ([] !== $event->getAttributes(NotApplicationController::class) || !is_a($class, ApplicationController::class, true)) {
            if (!$skipForgery) {
                $this->verifyAuthenticityToken($request);
            }

            return;
        }

        $response = $this->run($event, $request, $skipForgery);
        if (null !== $response) {
            $request->attributes->set(Pipeline::HALTED, true);
            $event->setController(static fn (): Response => $response);
        }
    }

    private function run(ControllerEvent $event, Request $request, bool $skipForgery): ?Response
    {
        $this->setVersionHeaders($request);
        $this->current->setRequest($request);

        // `reject_banned_ip, unless: :safe_request?` (GET or HEAD only).
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true) && $this->bans->isBanned((string) $request->getClientIp())) {
            return self::head(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $requireUnauthenticated = [] !== $event->getAttributes(RequireUnauthenticatedAccess::class);
        if (!$requireUnauthenticated && [] === $event->getAttributes(AllowUnauthenticatedAccess::class)) {
            if (null !== $response = $this->authentication->requireAuthentication($request)) {
                return $response;
            }
        }

        if ([] === $event->getAttributes(AllowBotAccess::class) && $this->current->isAuthenticatedByBotKey()) {
            return self::head(Response::HTTP_FORBIDDEN);
        }

        if (!$skipForgery && !$this->current->isAuthenticatedByBotKey()) {
            $this->verifyAuthenticityToken($request);
        }

        if (null !== $response = $this->browserPolicy->check($request)) {
            return $response;
        }

        if ($requireUnauthenticated) {
            $this->authentication->restoreAuthentication($request);

            return $this->authentication->redirectSignedInUserToRoot();
        }

        return null;
    }

    /** `verify_authenticity_token` with `with: :exception`. */
    private function verifyAuthenticityToken(Request $request): void
    {
        if (!$this->csrf->verify($request)) {
            throw new InvalidAuthenticityToken("Can't verify CSRF token authenticity.");
        }
    }

    /** reference/config/initializers/version.rb */
    private function setVersionHeaders(Request $request): void
    {
        $headers = ['X-Version' => '' !== $this->appVersion ? $this->appVersion : ('' !== $this->gitRevision ? $this->gitRevision : '0')];
        // `git_revision = ENV["GIT_REVISION"]`: Rack drops a nil header.
        if ('' !== $this->gitRevision) {
            $headers['X-Rev'] = $this->gitRevision;
        }
        $request->attributes->set(Pipeline::VERSION_HEADERS, $headers);
    }

    /**
     * `head status` from a before_action: the formats aren't set yet, so the content type is
     * text/html without charset.
     */
    private static function head(int $status): Response
    {
        return new Response('', $status, ['Content-Type' => 'text/html']);
    }
}
