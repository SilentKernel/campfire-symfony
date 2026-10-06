<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\Bans;
use App\Entity\User;
use App\Http\Concerns\ImplicitRender;
use App\Http\Exception\RecordNotFound;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Users::BansController (reference/app/controllers/users/bans_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class BansController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(
        private readonly Bans $bans,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/users/{user_id}/ban.{_format}', name: 'user_ban', methods: ['DELETE'], priority: 123)]
    public function destroy(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $user = $this->findUser($request);
        $this->bans->unban($user);

        return $this->redirectTo($this->generateUrl('user', ['id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/users/{user_id}/ban.{_format}', name: 'user_ban.post', methods: ['POST'], priority: 122)]
    public function create(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $user = $this->findUser($request);
        $this->bans->ban($user);

        return $this->redirectTo($this->generateUrl('user', ['id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** `set_user`: `User.find(params[:user_id])` */
    private function findUser(Request $request): User
    {
        $id = RubyInteger::cast($request->attributes->get('user_id'));
        $user = null === $id ? null : $this->users->find($id);

        return $user instanceof User ? $user : throw RecordNotFound::for('User', $request->attributes->get('user_id'));
    }
}
