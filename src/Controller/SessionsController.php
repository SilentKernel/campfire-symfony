<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Users\Push\PushSubscriptions;
use App\Domain\Users\SignInRateLimiter;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Http\Attribute\ActionNotFound;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Concerns\ImplicitRender;
use App\Rails\Password;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SessionsController (reference/app/controllers/sessions_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class SessionsController extends ApplicationController
{
    use ImplicitRender;

    /** A digest for timing parity when no user matches (`authenticate_by` hashes anyway). */
    private const string DUMMY_DIGEST = '$2a$12$2T3fhsKQ3iUokhdshv0G8uBjcqRoD5gFg7hgVPyG6qdZcl1lmbEOe';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SignInRateLimiter $rateLimiter,
        private readonly PushSubscriptions $pushSubscriptions,
    ) {
    }

    #[AllowUnauthenticatedAccess]
    #[Route('/session/new.{_format}', name: 'new_session', methods: ['GET'], priority: 166)]
    public function new(Request $request): Response
    {
        // `ensure_user_exists`
        if (0 === $this->users->count([])) {
            return $this->redirectTo($this->generateUrl('first_run', [], UrlGeneratorInterface::ABSOLUTE_URL));
        }

        return $this->renderHtml($request, 'sessions/new.html.twig', ['email_address' => $this->params($request)->get('email_address')]);
    }

    #[ActionNotFound]
    #[Route('/session/edit.{_format}', name: 'edit_session', methods: ['GET'], priority: 165)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for SessionsController");
    }

    #[ActionNotFound]
    #[Route('/session.{_format}', name: 'session', methods: ['GET'], priority: 164)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for SessionsController");
    }

    #[ActionNotFound]
    #[Route('/session.{_format}', name: 'session.patch', methods: ['PATCH'], priority: 163)]
    #[Route('/session.{_format}', name: 'session.put', methods: ['PUT'], priority: 162)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for SessionsController");
    }

    #[Route('/session.{_format}', name: 'session.delete', methods: ['DELETE'], priority: 161)]
    public function destroy(Request $request): Response
    {
        // `remove_push_subscription`
        $endpoint = $this->params($request)->get('push_subscription_endpoint');
        $user = $this->currentUser();
        if (null !== $endpoint && null !== $user) {
            $this->pushSubscriptions->destroyByEndpoint($user, \is_scalar($endpoint) ? (string) $endpoint : '');
        }

        $this->terminateCurrentSession();

        return $this->redirectTo($this->generateUrl('root', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[AllowUnauthenticatedAccess]
    #[Route('/session.{_format}', name: 'session.post', methods: ['POST'], priority: 160)]
    public function create(Request $request): Response
    {
        if (!$this->rateLimiter->attempt((string) $request->getClientIp())) {
            return $this->renderRejection($request, Response::HTTP_TOO_MANY_REQUESTS);
        }

        $params = $this->params($request);
        if (null !== $user = $this->authenticateBy($params->get('email_address'), $params->get('password'))) {
            $this->startNewSessionFor($user);

            return $this->redirectTo($this->postAuthenticatingUrl());
        }

        return $this->renderRejection($request, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * `User.active.authenticate_by(email_address:, password:)`: nil for a blank password, an
     * unknown address or a wrong password (bcrypt runs either way).
     */
    private function authenticateBy(mixed $emailAddress, mixed $password): ?User
    {
        if (!\is_string($password) || '' === $password) {
            return null;
        }

        $user = \is_string($emailAddress) ? $this->users->findActiveByEmailAddress($emailAddress) : null;
        if (null === $user || UserStatus::Active !== $user->getStatus()) {
            Password::verify($password, self::DUMMY_DIGEST);

            return null;
        }

        return Password::verify($password, $user->getPasswordDigest()) ? $user : null;
    }

    /** `render_rejection(status)`: the sign-in page again, with an alert for this request only. */
    private function renderRejection(Request $request, int $status): Response
    {
        $this->flash()->now('alert', 'Too many requests or unauthorized.');

        return $this->renderHtml($request, 'sessions/new.html.twig', ['email_address' => $this->params($request)->get('email_address')], $status);
    }
}
