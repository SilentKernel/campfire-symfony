<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Rooms\RoomsControllerBase;
use App\Domain\Rooms\RubyInteger;
use App\Entity\Room;
use App\Http\Attribute\ActionNotFound;
use App\Http\Params;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomsController (reference/app/controllers/rooms_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class RoomsController extends RoomsControllerBase
{
    #[Route('/rooms/{room_id}/@{message_id}.{_format}', name: 'room_at_message', methods: ['GET'], priority: 82)]
    #[Route('/rooms/{id}.{_format}', name: 'room', methods: ['GET'], priority: 77)]
    public function show(Request $request): Response
    {
        $room = $this->setRoom($request);
        $this->rememberLastRoomVisited($room);

        return $this->respondTo($request, ['html' => fn (): Response => $this->renderShow($room, $this->params($request))]);
    }

    #[Route('/rooms.{_format}', name: 'rooms', methods: ['GET'], priority: 81)]
    public function index(): Response
    {
        return $this->redirectToLastRoom();
    }

    #[ActionNotFound]
    #[Route('/rooms.{_format}', name: 'rooms.post', methods: ['POST'], priority: 80)]
    public function create(): never
    {
        throw new NotFoundHttpException("The action 'create' could not be found for RoomsController");
    }

    #[ActionNotFound]
    #[Route('/rooms/new.{_format}', name: 'new_room', methods: ['GET'], priority: 79)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for RoomsController");
    }

    #[ActionNotFound]
    #[Route('/rooms/{id}/edit.{_format}', name: 'edit_room', methods: ['GET'], priority: 78)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for RoomsController");
    }

    #[ActionNotFound]
    #[Route('/rooms/{id}.{_format}', name: 'room.patch', methods: ['PATCH'], priority: 76)]
    #[Route('/rooms/{id}.{_format}', name: 'room.put', methods: ['PUT'], priority: 75)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for RoomsController");
    }

    #[Route('/rooms/{id}.{_format}', name: 'room.delete', methods: ['DELETE'], priority: 74)]
    public function destroy(Request $request): Response
    {
        $room = $this->setRoom($request);
        $this->ensureCanAdminister($room);

        return $this->destroyRoom($room);
    }

    /** `find_messages` and the rooms/show view. */
    private function renderShow(Room $room, Params $params): Response
    {
        $messageId = $params->get('message_id');
        $around = null !== $messageId && !Params::isBlank($messageId) && null !== ($id = RubyInteger::cast($messageId))
            ? $this->kit->messages->find($room, $id)
            : null;
        $messages = null !== $around ? $this->kit->messages->pageAround($room, $around) : $this->kit->messages->lastPage($room);

        return $this->render('rooms/show.html.twig', [
            'room' => $room,
            'messages_html' => $this->kit->messageRenderer->render($messages),
            'invitation' => $room->getId() === $this->kit->rooms->originalId() && !$this->kit->messages->isPaged($room),
        ]);
    }
}
