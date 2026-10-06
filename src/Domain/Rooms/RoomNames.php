<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Entity\Room;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * `room_display_name(room, for_user:)` (reference/app/helpers/rooms_helper.rb): a direct room is
 * named after its other members (`room.users.without(for_user).pluck(:name).to_sentence`,
 * falling back to for_user's name), other rooms by their name. Memoized per request.
 */
final class RoomNames implements ResetInterface
{
    /** @var array<string, string> */
    private array $names = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function displayName(Room $room, ?User $forUser): string
    {
        if (!$room->isDirect()) {
            return (string) $room->getName();
        }

        $key = $room->getId().':'.($forUser?->getId() ?? '');
        if (!isset($this->names[$key])) {
            // The same SQL as Rails, so SQLite returns the names in the same order.
            $names = null !== $forUser
                ? $this->connection->fetchFirstColumn(
                    'SELECT "users"."name" FROM "users" INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id" WHERE "memberships"."room_id" = ? AND "users"."id" != ?',
                    [$room->getId(), $forUser->getId()],
                )
                : $this->connection->fetchFirstColumn(
                    'SELECT "users"."name" FROM "users" INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id" WHERE "memberships"."room_id" = ? AND "users"."id" IS NOT NULL',
                    [$room->getId()],
                );
            $sentence = self::toSentence(array_map(strval(...), $names));
            $this->names[$key] = '' !== $sentence ? $sentence : ($forUser?->getName() ?? '');
        }

        return $this->names[$key];
    }

    /**
     * Array#to_sentence: "a", "a and b", "a, b, and c" (or with another two-words connector).
     *
     * @param list<string> $words
     */
    public static function toSentence(array $words, string $twoWordsConnector = ' and '): string
    {
        return match (\count($words)) {
            0 => '',
            1 => $words[0],
            2 => $words[0].$twoWordsConnector.$words[1],
            default => implode(', ', \array_slice($words, 0, -1)).', and '.$words[\count($words) - 1],
        };
    }

    public function reset(): void
    {
        $this->names = [];
    }
}
