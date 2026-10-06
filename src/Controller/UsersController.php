<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\RecordNotUnique;
use App\Domain\Users\Users;
use App\Entity\User;
use App\Http\Attribute\RequireUnauthenticatedAccess;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use App\Http\Exception\RecordNotFound;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * UsersController (reference/app/controllers/users_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class UsersController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(
        private readonly Users $users,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[RequireUnauthenticatedAccess]
    #[Route('/join/{join_code}.{_format}', name: 'join', methods: ['GET'], priority: 128)]
    public function new(Request $request): Response
    {
        if (null !== $response = $this->verifyJoinCode($request)) {
            return $response;
        }

        return $this->renderHtml($request, 'users/new.html.twig', ['join_code' => $request->attributes->get('join_code')]);
    }

    #[RequireUnauthenticatedAccess]
    #[Route('/join/{join_code}.{_format}', name: 'join.post', methods: ['POST'], priority: 127)]
    public function create(Request $request): Response
    {
        if (null !== $response = $this->verifyJoinCode($request)) {
            return $response;
        }

        $userParams = $this->requirePermitted($request, 'user', 'name', 'avatar', 'email_address', 'password');
        try {
            $user = $this->users->create($userParams);
        } catch (RecordNotUnique) {
            $email = $userParams['email_address'] ?? null;

            // `new_session_url(email_address:)`: Rails' to_query escapes with CGI.escape.
            $url = $this->generateUrl('new_session', [], UrlGeneratorInterface::ABSOLUTE_URL);

            return $this->redirectTo(null === $email ? $url : $url.'?email_address='.str_replace('%7E', '~', urlencode(\is_scalar($email) ? (string) $email : '')));
        }
        $this->startNewSessionFor($user);

        return $this->redirectTo($this->generateUrl('root', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/users/{id}.{_format}', name: 'user', methods: ['GET'], priority: 104)]
    public function show(Request $request): Response
    {
        // `set_user`: `User.find(params[:id])`
        $id = RubyInteger::cast($request->attributes->get('id'));
        $user = null === $id ? null : $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw RecordNotFound::for('User', $request->attributes->get('id'));
        }

        return $this->renderHtml($request, 'users/show.html.twig', ['user' => $user]);
    }

    /** `verify_join_code`: 404 unless the join code is the account's. */
    private function verifyJoinCode(Request $request): ?Response
    {
        return $this->current()->account()?->getJoinCode() === $request->attributes->get('join_code') ? null : $this->filterHead(Response::HTTP_NOT_FOUND);
    }
}
