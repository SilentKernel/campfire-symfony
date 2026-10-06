<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Cable\Broadcaster;
use App\Cable\StreamNames;
use App\Entity\Enum\Involvement;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\User;
use App\Twig\Html\TurboStream;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The sidebar's Turbo Stream broadcasts from the room controllers
 * (reference/app/controllers/rooms/*.rb, rooms_controller.rb), on the streams the sidebar
 * subscribes to: `:rooms` (every user) and `[user, :rooms]`.
 */
final readonly class RoomBroadcasts
{
    public const string SHARED_TEMPLATE = 'users/sidebars/rooms/_shared.html.twig';

    public function __construct(
        private Broadcaster $broadcaster,
        private Environment $twig,
        private SidebarRenderer $sidebarRenderer,
        private Rooms $rooms,
        private EntityManagerInterface $em,
    ) {
    }

    /** `render partial: "users/sidebars/rooms/shared", locals: { room: }` */
    public function sharedRoomHtml(Room $room, bool $unread = false): string
    {
        return $this->twig->render(self::SHARED_TEMPLATE, ['room' => SidebarRoom::fromRoom($room), 'unread' => $unread]);
    }

    /** Opens#create: `broadcast_prepend_to :rooms, target: :shared_rooms, partial: shared`. */
    public function openRoomCreated(Room $room): void
    {
        $this->broadcaster->broadcast(StreamNames::rooms(), TurboStream::action('prepend', 'shared_rooms', $this->sharedRoomHtml($room)));
    }

    /** Opens#update: `broadcast_replace_to :rooms, target: [ @room, :list ], partial: shared`. */
    public function openRoomUpdated(Room $room): void
    {
        $this->broadcaster->broadcast(StreamNames::rooms(), TurboStream::action('replace', SidebarRoom::fromRoom($room)->listDomId(), $this->sharedRoomHtml($room)));
    }

    /** Closeds#create: the shared partial, rendered once, prepended on each member's own stream. */
    public function closedRoomCreated(Room $room): void
    {
        $this->eachUser($room, TurboStream::action('prepend', 'shared_rooms', $this->sharedRoomHtml($room)));
    }

    /** Closeds#update: the shared partial replacing the room's entry on each member's stream. */
    public function closedRoomUpdated(Room $room): void
    {
        $this->eachUser($room, TurboStream::action('replace', SidebarRoom::fromRoom($room)->listDomId(), $this->sharedRoomHtml($room)));
    }

    /**
     * Directs#create: `room.memberships.each { |m| m.broadcast_prepend_to m.user, :rooms,
     * target: :direct_rooms, partial: "users/sidebars/rooms/direct" }`, the partial cached as in
     * the sidebar.
     */
    public function directRoomCreated(Room $room): void
    {
        $memberships = $this->em->createQueryBuilder()->select('m', 'u')->from(Membership::class, 'm')->join('m.user', 'u')
            ->where('m.room = :room')->setParameter('room', $room->getId())->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
        foreach ($memberships as $membership) {
            \assert($membership instanceof Membership);
            $sidebarRoom = new SidebarRoom($room->getId(), $room->getName(), $room->getType(), $room->getUpdatedAt(), $membership->isUnread(), $membership->getId(), $membership->getUpdatedAt());
            $html = $this->sidebarRenderer->directRooms($this->twig, [$sidebarRoom], $membership->getUser());
            $this->broadcaster->broadcast(StreamNames::userRooms($membership->getUser()), TurboStream::action('prepend', 'direct_rooms', $html));
        }
    }

    /** RoomsController#destroy: `broadcast_remove_to :rooms, target: [ @room, :list ]`. */
    public function roomRemoved(Room $room): void
    {
        $this->broadcaster->broadcast(StreamNames::rooms(), TurboStream::actionTag('remove', SidebarRoom::fromRoom($room)->listDomId()));
    }

    /**
     * Involvements#update `broadcast_visibility_changes`: nothing for direct rooms; becoming
     * invisible removes the room from the user's sidebar, leaving invisible prepends it back.
     */
    public function involvementChanged(Room $room, User $user, ?Involvement $involvement, ?Involvement $previous): void
    {
        if ($room->isDirect()) {
            return;
        }
        if (Involvement::Invisible === $involvement) {
            $this->broadcaster->broadcast(StreamNames::userRooms($user), TurboStream::actionTag('remove', SidebarRoom::fromRoom($room)->listDomId()));
        } elseif (null === $previous) {
            // `involvement_previously_was.inquiry` on nil
            throw new \LogicException("undefined method 'inquiry' for nil");
        } elseif (Involvement::Invisible === $previous) {
            $this->broadcaster->broadcast(StreamNames::userRooms($user), TurboStream::action('prepend', 'shared_rooms', $this->sharedRoomHtml($room)));
        }
    }

    private function eachUser(Room $room, string $payload): void
    {
        foreach ($this->rooms->userIds($room) as $userId) {
            $this->broadcaster->broadcast(StreamNames::userRooms($userId), $payload);
        }
    }
}
