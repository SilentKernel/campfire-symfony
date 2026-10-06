<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Database\Transactions;
use App\Entity\Enum\UserStatus;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use App\Entity\User;
use App\Storage\Attachments;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Room model behaviour (reference/app/models/room.rb, rooms/open.rb, rooms/direct.rb).
 */
final readonly class Rooms
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private Memberships $memberships,
        private ClockInterface $clock,
        private Attachments $attachments,
    ) {
    }

    /**
     * `Room.create_for(attributes, users:)`: the room and its memberships in one transaction.
     * An open room then grants itself to every active user after commit
     * (Rooms::Open `after_save_commit :grant_access_to_all_users`).
     *
     * @template T of Room
     *
     * @param class-string<T>    $class
     * @param iterable<User|int> $users
     *
     * @return T
     */
    public function createFor(string $class, ?string $name, iterable $users, User $creator): Room
    {
        $userIds = Memberships::ids($users);

        return $this->transactions->transaction(function () use ($class, $name, $userIds, $creator): Room {
            $room = new $class($creator, $name);
            $this->em->persist($room);
            $this->em->flush();
            $this->memberships->grantTo($room, $userIds);
            if ($room instanceof Open) {
                $this->transactions->afterCommit(fn () => $this->grantToActiveUsers($room));
            }

            return $room;
        });
    }

    /**
     * `@room.becomes!(Rooms::Xxx)` then `@room.update!(attributes)`: saves the name and the STI
     * type when they changed (touching updated_at), and returns the room reloaded as its new
     * class. Becoming open grants every active user after commit. Direct rooms keep their type
     * (`direct_rooms_keep_their_type`).
     *
     * @template T of Room
     *
     * @param class-string<T>       $class
     * @param array{name?: ?string} $attributes
     *
     * @return T
     */
    public function update(Room $room, string $class, array $attributes = []): Room
    {
        $type = Room::TYPES[$class];
        if ($room->isDirect() && Direct::TYPE !== $type) {
            throw new \DomainException("Validation failed: Type can't be changed for a direct room");
        }

        $nameChanged = \array_key_exists('name', $attributes) && $attributes['name'] !== $room->getName();
        $typeChanged = $room->getType() !== $type;
        if (!$nameChanged && !$typeChanged) {
            \assert($room instanceof $class);

            return $room;
        }

        $id = $room->getId();
        $this->transactions->transaction(function () use ($id, $room, $type, $attributes, $nameChanged, $typeChanged): void {
            $this->connection->executeStatement(
                'UPDATE "rooms" SET "name" = ?, "type" = ?, "updated_at" = ? WHERE "rooms"."id" = ?',
                [$nameChanged ? $attributes['name'] : $room->getName(), $type, $this->clock->now(), $id],
                [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \App\Database\Type\RailsDateTimeType::NAME, \Doctrine\DBAL\ParameterType::INTEGER],
            );
            if ($typeChanged && Open::TYPE === $type) {
                $this->transactions->afterCommit(fn () => $this->grantToActiveUsers($this->reload($id)));
            }
        });

        $reloaded = $this->reload($id);
        \assert($reloaded instanceof $class);

        return $reloaded;
    }

    /**
     * `room.destroy`: memberships deleted without callbacks (`dependent: :delete_all`), each
     * message destroyed (`dependent: :destroy`, with its boosts, rich text body, search index row
     * and attachments, whose blobs are purged later), in bulk, then the room.
     */
    public function destroy(Room $room): void
    {
        $id = $room->getId();
        $this->transactions->transaction(function () use ($id): void {
            $c = $this->connection;
            $messages = '(SELECT "id" FROM "messages" WHERE "room_id" = ?)';
            $richTexts = '(SELECT "id" FROM "action_text_rich_texts" WHERE "record_type" = \'Message\' AND "name" = \'body\' AND "record_id" IN '.$messages.')';

            $c->executeStatement('DELETE FROM "memberships" WHERE "memberships"."room_id" = ?', [$id]);
            $c->executeStatement('DELETE FROM "message_search_index" WHERE "rowid" IN '.$messages, [$id]);
            $c->executeStatement('DELETE FROM "boosts" WHERE "message_id" IN '.$messages, [$id]);
            // has_one_attached :attachment and the rich texts' embeds (`dependent: :purge_later`)
            $attachments = $c->fetchAllAssociative(
                'SELECT "record_type", "record_id", "name" FROM "active_storage_attachments" WHERE ("record_type" = \'Message\' AND "record_id" IN '.$messages.') OR ("record_type" = \'ActionText::RichText\' AND "record_id" IN '.$richTexts.')',
                [$id, $id],
            );
            foreach ($attachments as $attachment) {
                $this->attachments->detach((string) $attachment['record_type'], (int) $attachment['record_id'], (string) $attachment['name']);
            }
            $c->executeStatement('DELETE FROM "action_text_rich_texts" WHERE "record_type" = \'Message\' AND "record_id" IN '.$messages, [$id]);
            $c->executeStatement('DELETE FROM "messages" WHERE "messages"."room_id" = ?', [$id]);
            $c->executeStatement('DELETE FROM "rooms" WHERE "rooms"."id" = ?', [$id]);
        });

        $this->forget($room);
    }

    /**
     * `Rooms::Direct.find_or_create_for(users)`: the direct room whose members are exactly these
     * users, else a new one created by $creator.
     *
     * @param iterable<User|int> $users
     */
    public function findOrCreateDirectFor(iterable $users, User $creator): Direct
    {
        $userIds = Memberships::ids($users);

        return $this->findDirectFor($userIds) ?? $this->createFor(Direct::class, null, $userIds, $creator);
    }

    /**
     * `Rooms::Direct.find_for(users)`: `all.joins(:users).detect { Set.new(room.user_ids) == Set.new(ids) }`.
     *
     * @param list<int> $userIds
     */
    public function findDirectFor(array $userIds): ?Direct
    {
        $wanted = array_values(array_unique($userIds));
        sort($wanted);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT "memberships"."room_id", "memberships"."user_id" FROM "rooms"'
            .' INNER JOIN "memberships" ON "memberships"."room_id" = "rooms"."id"'
            .' INNER JOIN "users" ON "users"."id" = "memberships"."user_id"'
            .' WHERE "rooms"."type" = ? ORDER BY "rooms"."id"',
            [Direct::TYPE],
        );
        $members = [];
        foreach ($rows as $row) {
            $members[(int) $row['room_id']][] = (int) $row['user_id'];
        }
        foreach ($members as $roomId => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            if ($ids === $wanted) {
                $room = $this->em->find(Room::class, $roomId);
                if ($room instanceof Direct) {
                    return $room;
                }
            }
        }

        return null;
    }

    /** `Room.original`: the oldest room. */
    public function original(): ?Room
    {
        $id = $this->connection->fetchOne('SELECT "rooms"."id" FROM "rooms" ORDER BY "rooms"."created_at" ASC LIMIT 1');

        return false === $id ? null : $this->em->find(Room::class, (int) $id);
    }

    /** The id of `Room.original`, without loading it. */
    public function originalId(): ?int
    {
        $id = $this->connection->fetchOne('SELECT "rooms"."id" FROM "rooms" ORDER BY "rooms"."created_at" ASC LIMIT 1');

        return false === $id ? null : (int) $id;
    }

    /**
     * `room.users` ids (`user_ids`), in the order SQLite returns the join (the covering
     * room_id/user_id index: by user id).
     *
     * @return list<int>
     */
    public function userIds(Room $room): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT "users"."id" FROM "users" INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id" WHERE "memberships"."room_id" = ?',
            [$room->getId()],
        ));
    }

    /**
     * `room.users` (optionally `.without(user)`), as entities.
     *
     * @return list<User>
     */
    public function users(Room $room, ?User $without = null): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT "users"."id" FROM "users" INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id" WHERE "memberships"."room_id" = ?'
            .(null !== $without ? ' AND "users"."id" != ?' : ''),
            null !== $without ? [$room->getId(), $without->getId()] : [$room->getId()],
        );

        return $this->usersInOrder(array_map(intval(...), $ids));
    }

    /**
     * Users by id, in the given order, in one query.
     *
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function usersInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::INTEGER)->getResult() as $user) {
            \assert($user instanceof User);
            $byId[$user->getId()] = $user;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?User => $byId[$id] ?? null, $ids)));
    }

    /**
     * `User.where(id: ids).pluck(:id)`: the ids that belong to existing users.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function existingUserIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT "users"."id" FROM "users" WHERE "users"."id" IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        ));
    }

    /** `memberships.grant_to(User.active)` */
    private function grantToActiveUsers(Room $room): void
    {
        $ids = $this->connection->fetchFirstColumn('SELECT "users"."id" FROM "users" WHERE "users"."status" = ?', [UserStatus::Active->value]);
        $this->memberships->grantTo($room, array_map(intval(...), $ids));
    }

    private function reload(int $id): Room
    {
        $loaded = $this->em->getUnitOfWork()->tryGetById($id, Room::class);
        if ($loaded instanceof Room) {
            $this->em->detach($loaded);
        }

        return $this->em->find(Room::class, $id) ?? throw new \RuntimeException(\sprintf("Couldn't find Room with 'id'=%d", $id));
    }

    /** Detaches the destroyed room and its loaded memberships and messages. */
    private function forget(Room $room): void
    {
        $identityMap = $this->em->getUnitOfWork()->getIdentityMap();
        foreach ([\App\Entity\Membership::class, Message::class] as $class) {
            foreach ($identityMap[$class] ?? [] as $entity) {
                if (method_exists($entity, 'getRoom') && $entity->getRoom() === $room) {
                    $this->em->detach($entity);
                }
            }
        }
        $this->em->detach($room);
    }
}
