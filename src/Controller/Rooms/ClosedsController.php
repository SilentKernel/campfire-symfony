<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Domain\Rooms\UserRooms;
use App\Entity\Rooms\Closed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rooms::ClosedsController (reference/app/controllers/rooms/closeds_controller.rb).
 * In Rails it subclasses RoomsController, whose filters and actions it inherits.
 */
#[Route(defaults: ['_format' => null])]
final class ClosedsController extends RoomsControllerBase
{
    /** DEFAULT_ROOM_NAME */
    public const string DEFAULT_ROOM_NAME = 'New room';

    /** Rails: inherited from RoomsController#index. */
    #[Route('/rooms/closeds.{_format}', name: 'rooms_closeds', methods: ['GET'], priority: 65)]
    public function index(): Response
    {
        return $this->redirectToLastRoom();
    }

    #[Route('/rooms/closeds.{_format}', name: 'rooms_closeds.post', methods: ['POST'], priority: 64)]
    public function create(Request $request): Response
    {
        $this->ensurePermissionToCreateRooms();
        $name = $this->roomParams($request)['name'] ?? null;
        $room = $this->kit->rooms->createFor(Closed::class, $name, $this->existingUserIds($this->userIdsParam($request)), $this->user());

        $this->kit->broadcasts->closedRoomCreated($room);

        return $this->redirectToRoom($room);
    }

    #[Route('/rooms/closeds/new.{_format}', name: 'new_rooms_closed', methods: ['GET'], priority: 63)]
    public function new(Request $request): Response
    {
        $this->ensurePermissionToCreateRooms();
        $room = new Closed($this->user(), self::DEFAULT_ROOM_NAME);

        return $this->respondTo($request, ['html' => fn (): Response => $this->render('rooms/closeds/new.html.twig', [
            'room' => $room,
            'room_is_new' => true,
            'users' => $this->kit->users->findActiveOrdered(),
            'form_url' => $this->generateUrl('rooms_closeds'),
            'form_method' => 'post',
            'can_administer' => true,
        ])]);
    }

    #[Route('/rooms/closeds/{id}/edit.{_format}', name: 'edit_rooms_closed', methods: ['GET'], priority: 62)]
    public function edit(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);

        return $this->respondTo($request, ['html' => function () use ($room): Response {
            $selectedIds = array_flip($this->kit->rooms->userIds($room));
            $selected = $unselected = [];
            foreach ($this->kit->users->findActiveOrdered() as $user) {
                if (isset($selectedIds[$user->getId()])) {
                    $selected[] = $user;
                } else {
                    $unselected[] = $user;
                }
            }

            return $this->render('rooms/closeds/edit.html.twig', [
                'room' => $room,
                'room_is_new' => false,
                'selected_users' => $selected,
                'unselected_users' => $unselected,
                'form_url' => $this->generateUrl('rooms_closed', ['id' => $room->getId()]),
                'form_method' => 'patch',
                'can_administer' => $this->user()->canAdminister($room),
            ]);
        }]);
    }

    #[Route('/rooms/closeds/{id}.{_format}', name: 'rooms_closed', methods: ['GET'], priority: 61)]
    public function show(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);
        $this->rememberLastRoomVisited($room);

        return $this->redirectToRoom($room);
    }

    #[Route('/rooms/closeds/{id}.{_format}', name: 'rooms_closed.patch', methods: ['PATCH'], priority: 60)]
    #[Route('/rooms/closeds/{id}.{_format}', name: 'rooms_closed.put', methods: ['PUT'], priority: 59)]
    public function update(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);
        $this->ensureCanAdminister($room);
        // force_room_type (becomes!), then `@room.update! room_params`
        $room = $this->kit->rooms->update($room, Closed::class, $this->roomParams($request));

        // `@room.memberships.revise(granted: grantees, revoked: revokees)`
        $granteeIds = $this->userIdsParam($request);
        $revokees = array_values(array_diff($this->kit->rooms->userIds($room), $granteeIds));
        $this->kit->memberships->revise($room, $this->existingUserIds($granteeIds), $revokees);

        $this->kit->broadcasts->closedRoomUpdated($room);

        return $this->redirectToRoom($room);
    }

    /** Rails: inherited from RoomsController#destroy, but this controller's set_room skips it. */
    #[Route('/rooms/closeds/{id}.{_format}', name: 'rooms_closed.delete', methods: ['DELETE'], priority: 58)]
    public function destroy(): never
    {
        $this->destroyWithoutRoom();
    }
}
