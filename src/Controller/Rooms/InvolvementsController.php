<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Controller\ApplicationController;
use App\Domain\Rooms\Memberships;
use App\Domain\Rooms\RoomBroadcasts;
use App\Domain\Rooms\UserRooms;
use App\Entity\Enum\Involvement;
use App\Entity\Membership;
use App\Http\Exception\RecordNotFound;
use App\Http\Params;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rooms::InvolvementsController (reference/app/controllers/rooms/involvements_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class InvolvementsController extends ApplicationController
{
    public function __construct(
        private readonly UserRooms $userRooms,
        private readonly Memberships $memberships,
        private readonly RoomBroadcasts $broadcasts,
    ) {
    }

    #[Route('/rooms/{room_id}/involvement.{_format}', name: 'room_involvement', methods: ['GET'], priority: 85)]
    public function show(Request $request): Response
    {
        $membership = $this->setRoom($request);

        return $this->respondTo($request, ['html' => fn (): Response => $this->render('rooms/involvements/show.html.twig', [
            'room' => $membership->getRoom(),
            'involvement' => $membership->getInvolvement(),
        ])]);
    }

    #[Route('/rooms/{room_id}/involvement.{_format}', name: 'room_involvement.patch', methods: ['PATCH'], priority: 84)]
    #[Route('/rooms/{room_id}/involvement.{_format}', name: 'room_involvement.put', methods: ['PUT'], priority: 83)]
    public function update(Request $request): Response
    {
        $membership = $this->setRoom($request);
        $involvement = self::involvementParam($this->params($request)->get('involvement'));
        $previous = $this->memberships->updateInvolvement($membership, $involvement);

        $this->broadcasts->involvementChanged($membership->getRoom(), $membership->getUser(), $involvement, $previous);

        return $this->redirectTo($this->generateUrl('room_involvement', ['room_id' => $membership->getRoom()->getId()]));
    }

    /** RoomScoped#set_room: `Current.user.memberships.find_by!(room_id: params[:room_id])`. */
    private function setRoom(Request $request): Membership
    {
        $roomId = $this->params($request)->get('room_id');
        $user = $this->currentUser() ?? throw new \LogicException('No current user.');

        return $this->userRooms->membership($user, $roomId) ?? throw RecordNotFound::for('Membership', \is_scalar($roomId) ? (string) $roomId : null);
    }

    /**
     * The enum cast of `params[:involvement]`: blank is nil, an unknown value raises
     * ArgumentError ("'x' is not a valid involvement").
     */
    private static function involvementParam(mixed $value): ?Involvement
    {
        if (Params::isBlank($value)) {
            return null;
        }

        return \is_string($value) ? Involvement::tryFrom($value) ?? throw new \InvalidArgumentException(\sprintf("'%s' is not a valid involvement", $value)) : throw new \InvalidArgumentException('is not a valid involvement');
    }
}
