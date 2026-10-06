<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Accounts\Accounts;
use App\Domain\Accounts\AccountUsers;
use App\Domain\Rooms\TrackedRoomVisit;
use App\Entity\Enum\UserStatus;
use App\Http\Attribute\ActionNotFound;
use App\Http\Concerns\ImplicitRender;
use App\Http\Params;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * AccountsController (reference/app/controllers/accounts_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class AccountsController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountUsers $accountUsers,
        private readonly TrackedRoomVisit $trackedRoomVisit,
    ) {
    }

    #[ActionNotFound]
    #[Route('/account/new.{_format}', name: 'new_account', methods: ['GET'], priority: 135)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for AccountsController");
    }

    #[Route('/account/edit.{_format}', name: 'edit_account', methods: ['GET'], priority: 134)]
    public function edit(Request $request): Response
    {
        $account = $this->current()->account();
        $statuses = true === $this->currentUser()?->canAdminister() ? [UserStatus::Active, UserStatus::Banned] : [UserStatus::Active];
        $users = $this->accountUsers->ordered($statuses);
        $page = $this->accountUsers->page($statuses, $this->params($request)->get('page'));

        return $this->renderHtml($request, 'accounts/edit.html.twig', [
            'account' => $account,
            'administrators' => array_values(array_filter($users, static fn ($user): bool => $user->isAdministrator())),
            'members' => array_values(array_filter($users, static fn ($user): bool => !$user->isAdministrator())),
            'page' => $page,
            'last_room' => $this->trackedRoomVisit->lastRoomVisited(),
        ]);
    }

    #[ActionNotFound]
    #[Route('/account.{_format}', name: 'account', methods: ['GET'], priority: 133)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for AccountsController");
    }

    #[Route('/account.{_format}', name: 'account.patch', methods: ['PATCH'], priority: 132)]
    #[Route('/account.{_format}', name: 'account.put', methods: ['PUT'], priority: 131)]
    public function update(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }

        $account = $this->params($request)->require('account');
        if (!$account instanceof Params) {
            throw new \UnexpectedValueException("undefined method 'permit'");
        }
        $attributes = $account->permit('name', 'logo');
        $settings = $account->get('settings');
        if (\is_array($settings) && !array_is_list($settings)) {
            $attributes['settings'] = array_filter($settings, static fn (mixed $value): bool => null === $value || \is_scalar($value));
        }
        $this->accounts->update($this->current()->account() ?? throw new \LogicException('No account'), $attributes);

        return $this->redirectTo($this->generateUrl('edit_account', [], UrlGeneratorInterface::ABSOLUTE_URL), notice: '✓');
    }

    #[ActionNotFound]
    #[Route('/account.{_format}', name: 'account.delete', methods: ['DELETE'], priority: 130)]
    public function destroy(): never
    {
        throw new NotFoundHttpException("The action 'destroy' could not be found for AccountsController");
    }

    #[ActionNotFound]
    #[Route('/account.{_format}', name: 'account.post', methods: ['POST'], priority: 129)]
    public function create(): never
    {
        throw new NotFoundHttpException("The action 'create' could not be found for AccountsController");
    }
}
