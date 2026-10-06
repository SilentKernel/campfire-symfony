<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Controller\ApplicationController;
use App\Domain\Accounts\AccountUsers;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\Users;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Http\Attribute\ActionNotFound;
use App\Http\Concerns\ImplicitRender;
use App\Http\Exception\RecordNotFound;
use App\Http\Params;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts::UsersController (reference/app/controllers/accounts/users_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class UsersController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(
        private readonly AccountUsers $accountUsers,
        private readonly Users $users,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/account/users.{_format}', name: 'account_users', methods: ['GET'], priority: 159)]
    public function index(Request $request): Response
    {
        $page = $this->accountUsers->page([UserStatus::Active], $this->params($request)->get('page'));

        return $this->renderTurboStream($request, 'accounts/users/index.turbo_stream.twig', ['page' => $page]);
    }

    #[ActionNotFound]
    #[Route('/account/users.{_format}', name: 'account_users.post', methods: ['POST'], priority: 158)]
    public function create(): never
    {
        throw new NotFoundHttpException("The action 'create' could not be found for Accounts::UsersController");
    }

    #[ActionNotFound]
    #[Route('/account/users/new.{_format}', name: 'new_account_user', methods: ['GET'], priority: 157)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for Accounts::UsersController");
    }

    #[ActionNotFound]
    #[Route('/account/users/{id}/edit.{_format}', name: 'edit_account_user', methods: ['GET'], priority: 156)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for Accounts::UsersController");
    }

    #[ActionNotFound]
    #[Route('/account/users/{id}.{_format}', name: 'account_user', methods: ['GET'], priority: 155)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for Accounts::UsersController");
    }

    #[Route('/account/users/{id}.{_format}', name: 'account_user.patch', methods: ['PATCH'], priority: 154)]
    #[Route('/account/users/{id}.{_format}', name: 'account_user.put', methods: ['PUT'], priority: 153)]
    public function update(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $user = $this->findActiveUser($request);

        // `{ role: params.require(:user)[:role].presence_in(%w[ member administrator ]) || "member" }`
        $userParams = $this->params($request)->require('user');
        $role = $userParams instanceof Params ? $userParams->get('role') : null;
        $this->users->updateRole($user, 'administrator' === $role ? UserRole::Administrator : UserRole::Member);

        return $this->redirectTo($this->generateUrl('edit_account', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/account/users/{id}.{_format}', name: 'account_user.delete', methods: ['DELETE'], priority: 152)]
    public function destroy(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $this->users->deactivate($this->findActiveUser($request));

        return $this->redirectTo($this->generateUrl('edit_account', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** `set_user`: `User.active.find(params[:user_id] || params[:id])` */
    private function findActiveUser(Request $request): User
    {
        $param = $this->params($request)->get('user_id') ?? $request->attributes->get('id');
        $id = RubyInteger::cast($param);

        return (null === $id ? null : $this->userRepository->findActive($id)) ?? throw RecordNotFound::for('User', $param);
    }
}
