<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Session;
use App\Entity\User;
use App\Http\Cookies;
use App\Http\Csrf;
use App\Http\Current;
use App\Http\Exception\UnknownFormat;
use App\Http\Exception\UnsafeRedirect;
use App\Http\Flash;
use App\Http\Halt;
use App\Http\Mime;
use App\Http\Params;
use App\Http\RailsSession;
use App\Security\Authentication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base of every controller that subclasses ApplicationController in Rails
 * (reference/app/controllers/application_controller.rb): App\Http\EventListener\FilterChainListener
 * runs its before_actions for these controllers. The helpers port the ActionController methods the
 * Rails controllers call.
 */
abstract class ApplicationController extends AbstractController
{
    public const string HTML = 'text/html; charset=utf-8';
    public const string TURBO_STREAM = 'text/vnd.turbo-stream.html; charset=utf-8';

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            Current::class => Current::class,
            Csrf::class => Csrf::class,
            Flash::class => Flash::class,
            RailsSession::class => RailsSession::class,
            Cookies::class => Cookies::class,
            Authentication::class => Authentication::class,
        ];
    }

    /** `Current` (user, session, request, account, authenticatedBy). */
    protected function current(): Current
    {
        return $this->container->get(Current::class);
    }

    protected function currentUser(): ?User
    {
        return $this->current()->user();
    }

    /** `signed_in?` */
    protected function signedIn(): bool
    {
        return $this->current()->isSignedIn();
    }

    protected function flash(): Flash
    {
        return $this->container->get(Flash::class);
    }

    /** Rails' `session`. */
    protected function session(): RailsSession
    {
        return $this->container->get(RailsSession::class);
    }

    protected function csrf(): Csrf
    {
        return $this->container->get(Csrf::class);
    }

    protected function cookies(): Cookies
    {
        return $this->container->get(Cookies::class);
    }

    /** `params` */
    protected function params(Request $request): Params
    {
        return Params::fromRequest($request);
    }

    /**
     * `head status` inside an action: an empty body typed as the request format (text/html when
     * it is a wildcard or unknown), no charset.
     *
     * @param array<string, string> $headers
     */
    protected function head(int $status, array $headers = [], ?Request $request = null): Response
    {
        $response = new Response('', $status, $headers);
        if (!\in_array($status, [204, 205, 304], true) && $status >= 200 && !$response->headers->has('Content-Type')) {
            $format = null !== ($request ??= $this->current()->request()) ? Mime::format($request) : null;
            $response->headers->set('Content-Type', null === $format || Mime::ALL === $format ? 'text/html' : Mime::typeOf($format));
        }

        return $response;
    }

    /** `render turbo_stream: ...` */
    protected function turboStream(string $html, int $status = 200): Response
    {
        return new Response($html, $status, ['Content-Type' => self::TURBO_STREAM]);
    }

    /** `render html:` / a rendered template, as Rails types it. */
    protected function html(string $html, int $status = 200): Response
    {
        return new Response($html, $status, ['Content-Type' => self::HTML]);
    }

    /** @param array<string, mixed> $parameters */
    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $response = parent::render($view, $parameters, $response);
        if (!$response->headers->has('Content-Type') || str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Type', self::HTML);
        }

        return $response;
    }

    /**
     * `redirect_to url, status:` with Rails' open-redirect protection (load_defaults 8.2:
     * other hosts and path-relative locations raise). Absolute paths gain the request's
     * protocol and host; the flash options set `flash[:notice]`/`flash[:alert]`.
     */
    protected function redirectTo(string $url, int $status = 302, bool $allowOtherHost = false, ?string $notice = null, ?string $alert = null): Response
    {
        $request = $this->current()->request() ?? $this->container->get('request_stack')->getCurrentRequest();
        $location = str_replace(["\0", "\r", "\n"], '', $url);

        if (1 !== preg_match('#\A([a-z][a-z\d\-+.]*:|//)#i', $location)) {
            if ('' !== $location && !str_starts_with($location, '/') && !str_starts_with($location, '?')) {
                throw new UnsafeRedirect(\sprintf('Path relative URL redirect detected: "%s"', $url));
            }
            $location = (null !== $request ? $request->getSchemeAndHttpHost() : '').$location;
        } elseif (!$allowOtherHost && !$this->isUrlHostAllowed($location, $request)) {
            throw new UnsafeRedirect(\sprintf('Unsafe redirect to "%s", pass allowOtherHost: true to redirect anyway.', $url));
        }

        if (null !== $notice) {
            $this->flash()->set('notice', $notice);
        }
        if (null !== $alert) {
            $this->flash()->set('alert', $alert);
        }

        return new Response('', $status, ['Location' => $location, 'Content-Type' => self::HTML]);
    }

    /** `redirect_back_or_to fallback`: the Referer when it is on this host, else the fallback. */
    protected function redirectBackOr(string $fallback, int $status = 302, ?string $notice = null, ?string $alert = null): Response
    {
        $request = $this->current()->request() ?? $this->container->get('request_stack')->getCurrentRequest();
        $referer = $request?->headers->get('Referer');
        if (null !== $referer && '' !== $referer && $this->isUrlHostAllowed($referer, $request)) {
            return $this->redirectTo($referer, $status, notice: $notice, alert: $alert);
        }

        return $this->redirectTo($fallback, $status, notice: $notice, alert: $alert);
    }

    /**
     * `respond_to do |format| ... end`: the handler of the negotiated format, keys in the order
     * the Rails block declares them ('html', 'turbo_stream', 'json', or Mime::ALL for `any`).
     * Nothing acceptable is UnknownFormat (406). Adds `Vary: Accept` when the Accept header chose and the handler rendered (not redirected).
     *
     * @param array<string, callable(): Response> $handlers
     */
    protected function respondTo(Request $request, array $handlers): Response
    {
        $format = Mime::negotiate($request, array_keys($handlers)) ?? throw new UnknownFormat();
        if (Mime::ALL === $format) {
            $format = (string) array_key_first($handlers);
        }
        $response = ($handlers[$format] ?? $handlers[Mime::ALL])();

        // AbstractController::Rendering#render sets it (`_set_vary_header`); redirect_to and head don't.
        if (!$response->isRedirect() && Mime::shouldApplyVaryHeader($request) && !$response->headers->has('Vary')) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }

    /** `ensure_can_administer` (Authorization concern): 403 unless the user can administer. */
    protected function requireAdministrator(): void
    {
        if (true !== $this->currentUser()?->canAdminister()) {
            $this->halt($this->head(Response::HTTP_FORBIDDEN));
        }
    }

    /** Stops the action with $response, like a before_action that renders or redirects. */
    protected function halt(Response $response): never
    {
        throw new Halt($response);
    }

    /** `start_new_session_for(user)` */
    protected function startNewSessionFor(User $user): Session
    {
        return $this->container->get(Authentication::class)->startNewSessionFor($user);
    }

    /** `terminate_current_session` */
    protected function terminateCurrentSession(): void
    {
        $this->container->get(Authentication::class)->terminateCurrentSession();
    }

    /** `post_authenticating_url` */
    protected function postAuthenticatingUrl(): string
    {
        return $this->container->get(Authentication::class)->postAuthenticatingUrl();
    }

    /** `_url_host_allowed?` */
    private function isUrlHostAllowed(string $url, ?Request $request): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (false === parse_url($url)) {
            return false;
        }
        if (null === $host) {
            return str_starts_with($url, '/') && !str_starts_with($url, '//');
        }

        return null !== $request && strtolower($host) === strtolower($request->getHost());
    }
}
