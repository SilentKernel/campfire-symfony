<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Entity\Room;
use App\Twig\Html\RecordIdentifier;

/**
 * A room as the sidebar lists it (users/sidebars/rooms/_shared and _direct): read with DBAL,
 * not hydrated. `membershipId`/`membershipUpdatedAt` key the direct rooms' fragment cache.
 */
final readonly class SidebarRoom
{
    public function __construct(
        public int $id,
        public ?string $name,
        public string $type,
        public \DateTimeImmutable $updatedAt,
        public bool $unread = false,
        public ?int $membershipId = null,
        public ?\DateTimeImmutable $membershipUpdatedAt = null,
    ) {
    }

    public static function fromRoom(Room $room, bool $unread = false): self
    {
        return new self($room->getId(), $room->getName(), $room->getType(), $room->getUpdatedAt(), $unread);
    }

    public function isDirect(): bool
    {
        return \App\Entity\Rooms\Direct::TYPE === $this->type;
    }

    /** `dom_id(room, :list)`: "list_rooms_open_1". */
    public function listDomId(): string
    {
        return 'list_'.$this->paramKey().'_'.$this->id;
    }

    /**
     * The Doctrine class of the room's STI type.
     *
     * @return class-string<Room>
     */
    public function roomClass(): string
    {
        return array_search($this->type, Room::TYPES, true) ?: throw new \UnexpectedValueException(\sprintf('Unknown room type "%s".', $this->type));
    }

    /** `room.updated_at.to_fs(:epoch)` */
    public function updatedAtEpoch(): int
    {
        return Epoch::milliseconds($this->updatedAt);
    }

    /** RecordIdentifier's param key for this room's class ("rooms_open"). */
    public function paramKey(): string
    {
        return RecordIdentifier::paramKey($this->roomClass());
    }
}
