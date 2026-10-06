<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Cable\Broadcaster;
use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\Enum\Involvement;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Room membership writes (reference/app/models/room.rb `has_many :memberships do … end`,
 * membership.rb, membership/connectable.rb).
 *
 * `grant_to` is `Membership.insert_all`: one INSERT … ON CONFLICT DO NOTHING (the unique
 * index on room_id/user_id keeps existing memberships untouched) whose timestamps come from
 * SQLite's clock with millisecond precision, as Active Record's insert_all writes them.
 */
final readonly class Memberships
{
    /** SQLite's `STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW')`: Active Record's high_precision_current_timestamp. */
    public const string SQLITE_NOW = "STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW')";

    /** Rows per INSERT, well under SQLite's bound-variable limit (3 per row). */
    private const int INSERT_BATCH = 1000;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private Broadcaster $broadcaster,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `room.memberships.grant_to(users)`: memberships with the room's default involvement, for
     * the users who don't have one yet.
     *
     * @param iterable<User|int> $users
     */
    public function grantTo(Room $room, iterable $users): void
    {
        $this->insertAll($room->getId(), $room->getDefaultInvolvement(), self::ids($users));
    }

    /**
     * `Membership.insert_all(user_ids.map { { room_id:, user_id:, involvement: } })`.
     *
     * @param list<int> $userIds
     */
    public function insertAll(int $roomId, ?Involvement $involvement, array $userIds): void
    {
        foreach (array_chunk(array_values(array_unique($userIds)), self::INSERT_BATCH) as $batch) {
            $rows = implode(', ', array_fill(0, \count($batch), \sprintf('(%s, ?, ?, %s, ?)', self::SQLITE_NOW, self::SQLITE_NOW)));
            $params = [];
            $types = [];
            foreach ($batch as $userId) {
                array_push($params, $involvement?->value, $roomId, $userId);
                array_push($types, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER);
            }
            $this->connection->executeStatement(
                'INSERT INTO "memberships" ("created_at","involvement","room_id","updated_at","user_id") VALUES '.$rows.' ON CONFLICT  DO NOTHING',
                $params,
                $types,
            );
        }
    }

    /**
     * `room.memberships.revoke_from(users)`, i.e. `destroy_by user: users`: each membership is
     * destroyed, and after commit its user's cable connections are reset
     * (`after_destroy_commit { user.reset_remote_connections }`).
     *
     * @param iterable<User|int> $users
     */
    public function revokeFrom(Room $room, iterable $users): void
    {
        $userIds = self::ids($users);
        if ([] === $userIds) {
            return;
        }

        $this->transactions->transaction(function () use ($room, $userIds): void {
            $memberships = $this->connection->fetchAllAssociative(
                'SELECT "memberships"."id", "memberships"."user_id" FROM "memberships" WHERE "memberships"."room_id" = ? AND "memberships"."user_id" IN (?)',
                [$room->getId(), $userIds],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
            foreach ($memberships as $membership) {
                $this->connection->executeStatement('DELETE FROM "memberships" WHERE "memberships"."id" = ?', [(int) $membership['id']]);
                $userId = (int) $membership['user_id'];
                $this->transactions->afterCommit(fn () => $this->broadcaster->disconnectUser($userId, true));
            }
        });
        $this->forgetMemberships($room);
    }

    /**
     * `room.memberships.revise(granted:, revoked:)`.
     *
     * @param iterable<User|int> $granted
     * @param iterable<User|int> $revoked
     */
    public function revise(Room $room, iterable $granted = [], iterable $revoked = []): void
    {
        $granted = self::ids($granted);
        $revoked = self::ids($revoked);

        $this->transactions->transaction(function () use ($room, $granted, $revoked): void {
            if ([] !== $granted) {
                $this->grantTo($room, $granted);
            }
            if ([] !== $revoked) {
                $this->revokeFrom($room, $revoked);
            }
        });
    }

    /** `membership.read`: `update!(unread_at: nil)`. */
    public function read(Membership $membership): void
    {
        if (null === $membership->getUnreadAt()) {
            return;
        }
        $this->transactions->transaction(function () use ($membership): void {
            $membership->setUnreadAt(null);
            $this->em->flush();
        });
    }

    /**
     * `membership.update!(involvement:)`. Returns the involvement before the update
     * (`involvement_previously_was`, which is the current one when nothing changed).
     */
    public function updateInvolvement(Membership $membership, ?Involvement $involvement): ?Involvement
    {
        $previous = $membership->getInvolvement();
        if ($previous !== $involvement) {
            $this->transactions->transaction(function () use ($membership, $involvement): void {
                $membership->setInvolvement($involvement);
                $this->em->flush();
            });
        }

        return $previous;
    }

    /**
     * Room#unread_memberships: `memberships.visible.disconnected.where.not(user: message.creator)
     * .update_all(unread_at: message.created_at, updated_at: Time.current)`.
     */
    public function markUnread(int $roomId, int $creatorId, \DateTimeInterface $messageCreatedAt): void
    {
        $now = $this->clock->now();
        $this->connection->executeStatement(
            'UPDATE "memberships" SET "unread_at" = ?, "updated_at" = ? WHERE "memberships"."room_id" = ? AND "memberships"."involvement" != ? AND '
                .self::disconnectedCondition().' AND "memberships"."user_id" != ?',
            [$messageCreatedAt, $now, $roomId, Involvement::Invisible->value, self::connectionCutoff($now), $creatorId],
            [RailsDateTimeType::NAME, RailsDateTimeType::NAME, ParameterType::INTEGER, ParameterType::STRING, RailsDateTimeType::NAME, ParameterType::INTEGER],
        );
    }

    /** `Membership.disconnect_all`: `connected.update_all connected_at: nil, connections: 0, updated_at: Time.current`. */
    public function disconnectAll(): int
    {
        $now = $this->clock->now();

        return (int) $this->connection->executeStatement(
            'UPDATE "memberships" SET "connected_at" = NULL, "connections" = 0, "updated_at" = ? WHERE '.self::connectedCondition(),
            [$now, self::connectionCutoff($now)],
            [RailsDateTimeType::NAME, RailsDateTimeType::NAME],
        );
    }

    /** `Membership.connect(membership, connections)`: no updated_at, unread cleared. */
    public function connect(Membership|int $membership, int $connections): void
    {
        $this->connection->executeStatement(
            'UPDATE "memberships" SET "connections" = ?, "connected_at" = ?, "unread_at" = NULL WHERE "memberships"."id" = ?',
            [$connections, $this->clock->now(), $membership instanceof Membership ? $membership->getId() : $membership],
            [ParameterType::INTEGER, RailsDateTimeType::NAME, ParameterType::INTEGER],
        );
    }

    /** `connected` scope: `where(connected_at: CONNECTION_TTL.ago..)`; binds the cutoff once. */
    public static function connectedCondition(string $table = 'memberships'): string
    {
        return \sprintf('"%s"."connected_at" >= ?', $table);
    }

    /** `disconnected` scope: `where(connected_at: [ nil, ...CONNECTION_TTL.ago ])`; binds the cutoff once. */
    public static function disconnectedCondition(string $table = 'memberships'): string
    {
        return \sprintf('("%1$s"."connected_at" IS NULL OR "%1$s"."connected_at" < ?)', $table);
    }

    /** `unread` scope. */
    public static function unreadCondition(string $table = 'memberships'): string
    {
        return \sprintf('"%s"."unread_at" IS NOT NULL', $table);
    }

    /** `CONNECTION_TTL.ago` */
    public static function connectionCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', Membership::CONNECTION_TTL));
    }

    /**
     * @param iterable<User|int> $users
     *
     * @return list<int>
     */
    public static function ids(iterable $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $ids[] = $user instanceof User ? $user->getId() : $user;
        }

        return array_values(array_unique($ids));
    }

    /** Drops loaded Membership entities of the room that DBAL just deleted. */
    private function forgetMemberships(Room $room): void
    {
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Membership::class] ?? [] as $membership) {
            if ($membership instanceof Membership && $membership->getRoom() === $room && null === $this->connection->fetchOne('SELECT 1 FROM "memberships" WHERE "id" = ?', [$membership->getId()])) {
                $this->em->detach($membership);
            }
        }
    }
}
