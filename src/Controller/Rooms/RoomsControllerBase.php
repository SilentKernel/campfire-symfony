<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Rooms\UserRooms;
use App\Entity\Room;
use App\Entity\User;
use App\Http\Params;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoomsController's private methods, shared with the controllers that subclass it in Rails
 * (reference/app/controllers/rooms_controller.rb, rooms/opens_controller.rb, …). Each Rails
 * subclass re-declares `before_action :set_room` with its own `only:`, which replaces the
 * parent's: the inherited actions it leaves out run without @room (see the controllers).
 */
abstract class RoomsControllerBase extends ApplicationController
{
    public function __construct(protected readonly RoomsKit $kit)
    {
    }

    /**
     * `set_room`: `room_scope.find_by(id: params[:room_id] || params[:id])`, else back to the
     * root with an alert.
     */
    protected function setRoom(Request $request, string $scope = UserRooms::ALL): Room
    {
        $params = $this->params($request);
        $room = $this->kit->userRooms->find($this->user(), $params->get('room_id') ?? $params->get('id'), $scope);
        if (null === $room) {
            $this->halt($this->redirectTo($this->generateUrl('root'), alert: 'Room not found or inaccessible'));
        }

        return $room;
    }

    /** `ensure_can_administer`: `head :forbidden unless Current.user.can_administer?(@room)`. */
    protected function ensureCanAdminister(Room $room): void
    {
        if (!$this->user()->canAdminister($room)) {
            $this->halt($this->head(Response::HTTP_FORBIDDEN));
        }
    }

    /** `ensure_permission_to_create_rooms` */
    protected function ensurePermissionToCreateRooms(): void
    {
        if (true === $this->current()->account()?->restrictsRoomCreationToAdministrators() && !$this->user()->isAdministrator()) {
            $this->halt($this->head(Response::HTTP_FORBIDDEN));
        }
    }

    /** `remember_last_room_visited` */
    protected function rememberLastRoomVisited(Room $room): void
    {
        $this->kit->trackedRoomVisit->rememberLastRoomVisited($room);
    }

    /**
     * `params.require(:room).permit(:name)`.
     *
     * @return array{name?: ?string}
     */
    protected function roomParams(Request $request): array
    {
        $room = $this->params($request)->require('room');
        if (!$room instanceof Params) {
            throw new \LogicException(\sprintf("undefined method 'permit' for an instance of %s", get_debug_type($room)));
        }
        $permitted = $room->permit('name');

        return \array_key_exists('name', $permitted) ? ['name' => null === $permitted['name'] ? null : (string) $permitted['name']] : [];
    }

    /**
     * `params.fetch(:user_ids, [])` cast to ids (values Active Record can't cast are dropped).
     *
     * @return list<int>
     */
    protected function userIdsParam(Request $request): array
    {
        $value = $this->params($request)->get('user_ids', []);
        $values = \is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(RubyInteger::cast(...), $values), static fn (?int $id): bool => null !== $id)));
    }

    /**
     * `User.where(id: ids)`: the ids of existing users.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    protected function existingUserIds(array $ids): array
    {
        return $this->kit->rooms->existingUserIds($ids);
    }

    protected function redirectToRoom(Room $room): Response
    {
        return $this->redirectTo($this->generateUrl('room', ['id' => $room->getId()]));
    }

    /** RoomsController#index: `redirect_to room_url(Current.user.rooms.last)`; with no room, room_url(nil) raises. */
    protected function redirectToLastRoom(): Response
    {
        $room = $this->kit->userRooms->last($this->user()) ?? throw new \LogicException('No route matches room_url(nil)');

        return $this->redirectToRoom($room);
    }

    /** `@room.destroy`, `broadcast_remove_to :rooms, target: [ @room, :list ]`, then to the root. */
    protected function destroyRoom(Room $room): Response
    {
        $this->kit->rooms->destroy($room);
        $this->kit->broadcasts->roomRemoved($room);

        return $this->redirectTo($this->generateUrl('root'));
    }

    /** `@room.destroy` with `set_room` skipped: @room is nil. */
    protected function destroyWithoutRoom(): never
    {
        throw new \LogicException("undefined method 'destroy' for nil");
    }

    protected function user(): User
    {
        return $this->currentUser() ?? throw new \LogicException('No current user.');
    }
}
