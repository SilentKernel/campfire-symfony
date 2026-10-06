<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Domain\Rooms\UserRooms;
use App\Entity\Rooms\Open;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rooms::OpensController (reference/app/controllers/rooms/opens_controller.rb).
 * In Rails it subclasses RoomsController, whose filters and actions it inherits.
 */
#[Route(defaults: ['_format' => null])]
final class OpensController extends RoomsControllerBase
{
    /** DEFAULT_ROOM_NAME */
    public const string DEFAULT_ROOM_NAME = 'New room';

    /** Rails: inherited from RoomsController#index. */
    #[Route('/rooms/opens.{_format}', name: 'rooms_opens', methods: ['GET'], priority: 73)]
    public function index(): Response
    {
        return $this->redirectToLastRoom();
    }

    #[Route('/rooms/opens.{_format}', name: 'rooms_opens.post', methods: ['POST'], priority: 72)]
    public function create(Request $request): Response
    {
        $this->ensurePermissionToCreateRooms();
        $room = $this->kit->rooms->createFor(Open::class, $this->roomParams($request)['name'] ?? null, [$this->user()], $this->user());

        $this->kit->broadcasts->openRoomCreated($room);

        return $this->redirectToRoom($room);
    }

    #[Route('/rooms/opens/new.{_format}', name: 'new_rooms_open', methods: ['GET'], priority: 71)]
    public function new(Request $request): Response
    {
        $this->ensurePermissionToCreateRooms();
        $room = new Open($this->user(), self::DEFAULT_ROOM_NAME);

        return $this->respondTo($request, ['html' => fn (): Response => $this->render('rooms/opens/new.html.twig', [
            'room' => $room,
            'users' => $this->kit->users->findActiveOrdered(),
            'form_url' => $this->generateUrl('rooms_opens'),
            'form_method' => 'post',
            'can_administer' => true,
        ])]);
    }

    #[Route('/rooms/opens/{id}/edit.{_format}', name: 'edit_rooms_open', methods: ['GET'], priority: 70)]
    public function edit(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);

        return $this->respondTo($request, ['html' => fn (): Response => $this->render('rooms/opens/edit.html.twig', [
            'room' => $room,
            'users' => $this->kit->users->findActiveOrdered(),
            'form_url' => $this->generateUrl('rooms_open', ['id' => $room->getId()]),
            'form_method' => 'patch',
            'can_administer' => $this->user()->canAdminister($room),
        ])]);
    }

    #[Route('/rooms/opens/{id}.{_format}', name: 'rooms_open', methods: ['GET'], priority: 69)]
    public function show(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);
        $this->rememberLastRoomVisited($room);

        return $this->redirectToRoom($room);
    }

    #[Route('/rooms/opens/{id}.{_format}', name: 'rooms_open.patch', methods: ['PATCH'], priority: 68)]
    #[Route('/rooms/opens/{id}.{_format}', name: 'rooms_open.put', methods: ['PUT'], priority: 67)]
    public function update(Request $request): Response
    {
        $room = $this->setRoom($request, UserRooms::WITHOUT_DIRECTS);
        $this->ensureCanAdminister($room);
        // force_room_type (becomes!), then `@room.update! room_params`
        $room = $this->kit->rooms->update($room, Open::class, $this->roomParams($request));

        $this->kit->broadcasts->openRoomUpdated($room);

        return $this->redirectToRoom($room);
    }

    /** Rails: inherited from RoomsController#destroy, but this controller's set_room skips it. */
    #[Route('/rooms/opens/{id}.{_format}', name: 'rooms_open.delete', methods: ['DELETE'], priority: 66)]
    public function destroy(): never
    {
        $this->destroyWithoutRoom();
    }
}
