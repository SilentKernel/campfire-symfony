<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Controller\ApplicationController;
use App\Domain\Users\Users;
use App\Entity\Membership;
use App\Entity\User;
use App\Http\Attribute\ActionNotFound;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Users::ProfilesController (reference/app/controllers/users/profiles_controller.rb).
 */
#[Route(defaults: ['_format' => null, 'user_id' => 'me'])]
final class ProfilesController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(
        private readonly Users $users,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/profile/new.{_format}', name: 'new_user_profile', methods: ['GET'], priority: 120)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for Users::ProfilesController");
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/profile/edit.{_format}', name: 'edit_user_profile', methods: ['GET'], priority: 119)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for Users::ProfilesController");
    }

    #[Route('/users/{user_id}/profile.{_format}', name: 'user_profile', methods: ['GET'], priority: 118)]
    public function show(Request $request): Response
    {
        $user = $this->user();
        $direct = $shared = [];
        foreach ($this->membershipsWithOrderedRoom($user) as $membership) {
            if ($membership->getRoom()->isDirect()) {
                $direct[] = $membership;
            } else {
                $shared[] = $membership;
            }
        }

        return $this->renderHtml($request, 'users/profiles/show.html.twig', [
            'user' => $user,
            'direct_memberships' => $direct,
            'shared_memberships' => $shared,
        ]);
    }

    #[Route('/users/{user_id}/profile.{_format}', name: 'user_profile.patch', methods: ['PATCH'], priority: 117)]
    #[Route('/users/{user_id}/profile.{_format}', name: 'user_profile.put', methods: ['PUT'], priority: 116)]
    public function update(Request $request): Response
    {
        $user = $this->user();
        $userParams = array_filter(
            $this->requirePermitted($request, 'user', 'name', 'avatar', 'email_address', 'password', 'bio'),
            static fn (mixed $value): bool => null !== $value,
        );
        $this->users->update($user, $userParams);

        // `params[:user][:avatar] ? "It may take up to 30 minutes to change everywhere." : "✓"`
        $avatar = $this->params($request)->get('user.avatar');
        $notice = null !== $avatar && false !== $avatar ? 'It may take up to 30 minutes to change everywhere.' : '✓';

        return $this->redirectTo($this->generateUrl('user_profile', [], UrlGeneratorInterface::ABSOLUTE_URL), notice: $notice);
    }

    /** `set_user`: always the signed-in user. */
    private function user(): User
    {
        return $this->currentUser() ?? throw new \LogicException('Not signed in');
    }

    /**
     * `Current.user.memberships.with_ordered_room`: the same SQL ordering as Rails, so rooms with
     * equal names (direct rooms have none) come in SQLite's order.
     *
     * @return list<Membership>
     */
    private function membershipsWithOrderedRoom(User $user): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT "memberships"."id" FROM "memberships" INNER JOIN "rooms" ON "rooms"."id" = "memberships"."room_id" WHERE "memberships"."user_id" = ? ORDER BY LOWER(rooms.name)',
            [$user->getId()],
        );
        if ([] === $ids) {
            return [];
        }
        $memberships = [];
        foreach ($this->em->createQueryBuilder()->select('m', 'r')->from(Membership::class, 'm')->join('m.room', 'r')->where('m.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $membership) {
            $memberships[$membership->getId()] = $membership;
        }

        return array_values(array_filter(array_map(static fn (mixed $id): ?Membership => $memberships[(int) $id] ?? null, $ids)));
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/profile.{_format}', name: 'user_profile.delete', methods: ['DELETE'], priority: 115)]
    public function destroy(): never
    {
        throw new NotFoundHttpException("The action 'destroy' could not be found for Users::ProfilesController");
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/profile.{_format}', name: 'user_profile.post', methods: ['POST'], priority: 114)]
    public function create(): never
    {
        throw new NotFoundHttpException("The action 'create' could not be found for Users::ProfilesController");
    }
}
