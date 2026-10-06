<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Domain\Messages\MessagePages;
use App\Domain\Rooms\Memberships;
use App\Domain\Rooms\RoomBroadcasts;
use App\Domain\Rooms\Rooms;
use App\Domain\Rooms\TrackedRoomVisit;
use App\Domain\Rooms\UserRooms;
use App\Repository\UserRepository;
use App\View\MessageRenderer;

/** The services RoomsController and its Rails subclasses share. */
final readonly class RoomsKit
{
    public function __construct(
        public UserRooms $userRooms,
        public Rooms $rooms,
        public Memberships $memberships,
        public RoomBroadcasts $broadcasts,
        public MessagePages $messages,
        public MessageRenderer $messageRenderer,
        public TrackedRoomVisit $trackedRoomVisit,
        public UserRepository $users,
    ) {
    }
}
