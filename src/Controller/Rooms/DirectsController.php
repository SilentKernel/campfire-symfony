<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Domain\Rooms\UserRooms;
use App\Http\Attribute\ActionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rooms::DirectsController (reference/app/controllers/rooms/directs_controller.rb).
 * In Rails it subclasses RoomsController, whose filters and actions it inherits.
 */
#[Route(defaults: ['_format' => null])]
final class DirectsController extends RoomsControllerBase
{
    /** Rails: inherited from RoomsController#index. */
    #[Route('/rooms/directs.{_format}', name: 'rooms_directs', methods: ['GET'], priority: 57)]
    public function index(): Response
    {
        return $this->redirectToLastRoom();
    }

    #[Route('/rooms/directs.{_format}', name: 'rooms_directs.post', methods: ['POST'], priority: 56)]
    public function create(Request $request): Response
    {
        // selected_users: `User.where(id: selected_users_ids.including(Current.user.id))`
        $userIds = $this->existingUserIds([...$this->userIdsParam($request), $this->user()->getId()]);
        $room = $this->kit->rooms->findOrCreateDirectFor($userIds, $this->user());

        $this->kit->broadcasts->directRoomCreated($room);

        return $this->redirectToRoom($room);
    }

    #[Route('/rooms/directs/new.{_format}', name: 'new_rooms_direct', methods: ['GET'], priority: 55)]
    public function new(Request $request): Response
    {
        return $this->respondTo($request, ['html' => fn (): Response => $this->render('rooms/directs/new.html.twig')]);
    }

    #[Route('/rooms/directs/{id}/edit.{_format}', name: 'edit_rooms_direct', methods: ['GET'], priority: 54)]
    public function edit(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::DIRECTS);

        return $this->respondTo($request, ['html' => function () use ($room): Response {
            // `@room.users.many? ? @room.users.without(Current.user) : @room.users`
            $users = $this->kit->rooms->users($room);
            if (\count($users) > 1) {
                $users = $this->kit->rooms->users($room, $this->user());
            }

            return $this->render('rooms/directs/edit.html.twig', ['room' => $room, 'users' => $users]);
        }]);
    }

    /**
     * Rails: inherited from RoomsController#show without this controller's set_room, so
     * `remember_last_room_visited` raises on the nil @room.
     */
    #[Route('/rooms/directs/{id}.{_format}', name: 'rooms_direct', methods: ['GET'], priority: 53)]
    public function show(): never
    {
        throw new \LogicException("undefined method 'id' for nil");
    }

    #[ActionNotFound]
    #[Route('/rooms/directs/{id}.{_format}', name: 'rooms_direct.patch', methods: ['PATCH'], priority: 52)]
    #[Route('/rooms/directs/{id}.{_format}', name: 'rooms_direct.put', methods: ['PUT'], priority: 51)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for Rooms::DirectsController");
    }

    /**
     * Rails: inherited from RoomsController#destroy, with this controller's set_room (direct
     * rooms only) and an ensure_can_administer that lets every member through.
     */
    #[Route('/rooms/directs/{id}.{_format}', name: 'rooms_direct.delete', methods: ['DELETE'], priority: 50)]
    public function destroy(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::DIRECTS);

        return $this->destroyRoom($room);
    }
}
