<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Enum\Involvement;
use App\Entity\Enum\UserStatus;
use App\Entity\Rooms\Direct;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The data of Users::SidebarsController#show (reference/app/controllers/users/sidebars_controller.rb),
 * read with DBAL: the user's visible memberships with their rooms, split into direct rooms
 * (most recently updated first) and the others (by LOWER(name)), and the users to offer a
 * direct room with.
 */
final readonly class Sidebar
{
    /** Users::SidebarsController::DIRECT_PLACEHOLDERS */
    public const int DIRECT_PLACEHOLDERS = 20;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * `Current.user.memberships.visible.with_ordered_room.partition { |m| m.room.direct? }`,
     * the direct ones sorted by `room.updated_at`, newest first.
     *
     * @return array{list<SidebarRoom>, list<SidebarRoom>} [direct rooms, other rooms]
     */
    public function memberships(User $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT "memberships"."id" AS membership_id, "memberships"."updated_at" AS membership_updated_at, "memberships"."unread_at",'
            .' "rooms"."id", "rooms"."name", "rooms"."type", "rooms"."updated_at"'
            .' FROM "memberships" INNER JOIN "rooms" ON "rooms"."id" = "memberships"."room_id"'
            .' WHERE "memberships"."user_id" = ? AND "memberships"."involvement" != ? ORDER BY LOWER(rooms.name)',
            [$user->getId(), Involvement::Invisible->value],
            [ParameterType::INTEGER, ParameterType::STRING],
        );

        $directs = [];
        $others = [];
        foreach ($rows as $row) {
            $room = new SidebarRoom(
                (int) $row['id'],
                null === $row['name'] ? null : (string) $row['name'],
                (string) $row['type'],
                self::time($row['updated_at']),
                null !== $row['unread_at'],
                (int) $row['membership_id'],
                self::time($row['membership_updated_at']),
            );
            if ($room->isDirect()) {
                $directs[] = $room;
            } else {
                $others[] = $room;
            }
        }
        // sort_by { updated_at }.reverse
        usort($directs, static fn (SidebarRoom $a, SidebarRoom $b): int => $b->updatedAt <=> $a->updatedAt);

        return [$directs, $others];
    }

    /**
     * `find_direct_placeholder_users`: active users the user has no direct room with, oldest
     * first, up to DIRECT_PLACEHOLDERS minus the excluded ids. The excluded ids are the members
     * of the user's direct rooms (`uniq`) plus the user, appended again (`including`), so a user
     * with direct rooms counts twice, as in Rails.
     *
     * @return list<User>
     */
    public function directPlaceholderUsers(User $user): array
    {
        $memberIds = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT DISTINCT "memberships"."user_id" FROM "memberships" WHERE "memberships"."room_id" IN'
            .' (SELECT "rooms"."id" FROM "rooms" INNER JOIN "memberships" "mine" ON "rooms"."id" = "mine"."room_id" WHERE "mine"."user_id" = ? AND "rooms"."type" = ?)',
            [$user->getId(), Direct::TYPE],
        ));
        $excluded = [...$memberIds, $user->getId()];
        $limit = max(self::DIRECT_PLACEHOLDERS - \count($excluded), 0);
        if (0 === $limit) {
            return [];
        }

        // `User.active.where.not(id:).order(:created_at).limit(n)`, hydrated in the same query
        $users = $this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.status = :active AND u.id NOT IN (:excluded) ORDER BY u.createdAt ASC')
            ->setParameter('active', UserStatus::Active->value)
            ->setParameter('excluded', array_values(array_unique($excluded)), ArrayParameterType::INTEGER)
            ->setMaxResults($limit)
            ->getResult();

        return array_values(array_filter($users, static fn (mixed $user): bool => $user instanceof User));
    }

    /**
     * The members shown for each direct room: `membership.room.users.without(membership.user)
     * .presence || [ membership.user ]`, for several rooms in one query (same index order as
     * Rails' per-room query: by user id).
     *
     * @param list<int> $roomIds
     *
     * @return array<int, list<User>> by room id
     */
    public function directMembers(array $roomIds, User $user): array
    {
        if ([] === $roomIds) {
            return [];
        }
        // Pairs first: an entity query would collapse a user shared by several rooms into one row.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT "memberships"."room_id", "users"."id" FROM "users" INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id"'
            .' WHERE "memberships"."room_id" IN (?) AND "users"."id" != ? ORDER BY "memberships"."room_id", "memberships"."user_id"',
            [$roomIds, $user->getId()],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        );
        $users = [];
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        if ([] !== $ids) {
            foreach ($this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::INTEGER)->getResult() as $found) {
                \assert($found instanceof User);
                $users[$found->getId()] = $found;
            }
        }
        $usersByRoom = [];
        foreach ($rows as $row) {
            if (isset($users[(int) $row['id']])) {
                $usersByRoom[(int) $row['room_id']][] = $users[(int) $row['id']];
            }
        }

        $members = [];
        foreach ($roomIds as $roomId) {
            $members[$roomId] = $usersByRoom[$roomId] ?? [$user];
        }

        return $members;
    }

    private static function time(mixed $value): \DateTimeImmutable
    {
        return RailsDateTimeType::parse((string) $value) ?? throw new \UnexpectedValueException(\sprintf('Invalid timestamp "%s".', (string) $value));
    }
}
