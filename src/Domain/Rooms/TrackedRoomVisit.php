<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Entity\Room;
use App\Http\Cookies;
use App\Http\Current;
use Symfony\Contracts\Service\ResetInterface;

/**
 * reference/app/controllers/concerns/tracked_room_visit.rb: the `last_room` cookie (plain,
 * permanent) and `last_room_visited`, which falls back to the user's original room.
 */
final class TrackedRoomVisit implements ResetInterface
{
    public const string COOKIE = 'last_room';

    private ?Room $lastRoomVisited = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Current $current,
        private readonly Cookies $cookies,
        private readonly UserRooms $userRooms,
    ) {
    }

    /** `remember_last_room_visited`: `cookies.permanent[:last_room] = @room.id`. */
    public function rememberLastRoomVisited(Room $room): void
    {
        $this->cookies->set(self::COOKIE, (string) $room->getId(), ['permanent' => true]);
        $this->lastRoomVisited = null;
        $this->loaded = false;
    }

    /**
     * `last_room_visited`: `Current.user.rooms.find_by(id: cookies[:last_room]) || default_room`
     * (`Current.user.rooms.original`). Null when signed out or in no room.
     */
    public function lastRoomVisited(): ?Room
    {
        if ($this->loaded) {
            return $this->lastRoomVisited;
        }
        $user = $this->current->user();
        $this->loaded = true;
        if (null === $user) {
            return $this->lastRoomVisited = null;
        }

        return $this->lastRoomVisited = $this->userRooms->find($user, $this->cookies->get(self::COOKIE)) ?? $this->userRooms->original($user);
    }

    public function reset(): void
    {
        $this->lastRoomVisited = null;
        $this->loaded = false;
    }
}
