<?php

declare(strict_types=1);

namespace App\Cable\Server;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Enum\UserStatus;
use App\Entity\Membership;
use App\Entity\Room;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The cable server's reads and writes, as single short statements on its own connection (the
 * server runs one event loop, so nothing here may wait long: see CableCommand's busy timeout).
 * A locked database surfaces as Doctrine's LockWaitTimeoutException, which the caller retries.
 */
final readonly class CableRepository
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    /** Lowers SQLite's busy timeout for this connection: the server retries instead of waiting. */
    public function setBusyTimeout(int $milliseconds): void
    {
        $this->connection->executeStatement('PRAGMA busy_timeout = '.$milliseconds);
    }

    /**
     * Authentication::SessionLookup#find_session_by_cookie, then `session.user`. Users who are not
     * active are turned away too (their sessions are deleted on ban and deactivation anyway).
     *
     * @return array{id: int, name: string}|null
     */
    public function findUserBySessionToken(string $token): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT users.id, users.name, users.status FROM sessions INNER JOIN users ON users.id = sessions.user_id WHERE sessions.token = ? LIMIT 1',
            [$token],
        );
        if (false === $row || UserStatus::Active->value !== (int) $row['status']) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * `current_user.rooms.find_by(id:)`.
     *
     * @return array{id: int, type: string}|null
     */
    public function findRoomForUser(int $userId, int $roomId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT rooms.id, rooms.type FROM rooms INNER JOIN memberships ON rooms.id = memberships.room_id WHERE memberships.user_id = ? AND rooms.id = ? LIMIT 1',
            [$userId, $roomId],
        );

        return false === $row ? null : ['id' => (int) $row['id'], 'type' => (string) $row['type']];
    }

    /**
     * `Room.find(id)` or, for an STI class, `Rooms::Open.find(id)` (which requires that type).
     *
     * @return array{id: int, type: string}|null
     */
    public function findRoom(int $roomId, ?string $type = null): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, type FROM rooms WHERE id = ? LIMIT 1', [$roomId]);
        if (false === $row || (null !== $type && $type !== $row['type'])) {
            return null;
        }

        return ['id' => (int) $row['id'], 'type' => (string) $row['type']];
    }

    /** @return array{id: int, room_id: int, connections: int, connected_at: ?string}|null */
    public function findMembership(int $roomId, int $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, room_id, connections, connected_at FROM memberships WHERE room_id = ? AND user_id = ? LIMIT 1',
            [$roomId, $userId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'connections' => (int) $row['connections'],
            'connected_at' => null === $row['connected_at'] ? null : (string) $row['connected_at'],
        ];
    }

    // Membership::Connectable (reference/app/models/membership/connectable.rb)

    /**
     * `connected?`.
     *
     * @param array{connected_at: ?string} $membership
     */
    public function isConnected(array $membership): bool
    {
        if (null === $membership['connected_at']) {
            return false;
        }
        $connectedAt = RailsDateTimeType::parse($membership['connected_at']);

        return null !== $connectedAt && $connectedAt >= $this->ttlAgo();
    }

    /**
     * `present`: Membership.connect(membership, connected? ? connections + 1 : 1), an update_all
     * (no updated_at) that also marks the room read.
     *
     * @param array{id: int, connections: int, connected_at: ?string} $membership
     */
    public function present(array $membership): void
    {
        $connections = $this->isConnected($membership) ? $membership['connections'] + 1 : 1;
        $this->connection->executeStatement(
            'UPDATE memberships SET connections = ?, connected_at = ?, unread_at = NULL WHERE id = ?',
            [$connections, $this->now(), $membership['id']],
        );
    }

    /**
     * `disconnected`: decrement_connections, then `update! connected_at: nil if connections < 1`.
     *
     * @param array{id: int, connections: int, connected_at: ?string} $membership
     */
    public function disconnected(array $membership): void
    {
        $now = $this->now();
        if ($this->isConnected($membership)) {
            // decrement!(:connections, touch: true)
            $this->connection->executeStatement(
                'UPDATE memberships SET connections = COALESCE(connections, 0) - 1, updated_at = ? WHERE id = ?',
                [$now, $membership['id']],
            );
            $connections = $membership['connections'] - 1;
        } else {
            // update!(connections: 0): a save only when the value changes
            if (0 !== $membership['connections']) {
                $this->connection->executeStatement('UPDATE memberships SET connections = 0, updated_at = ? WHERE id = ?', [$now, $membership['id']]);
            }
            $connections = 0;
        }
        if ($connections < 1 && null !== $membership['connected_at']) {
            $this->connection->executeStatement('UPDATE memberships SET connected_at = NULL, updated_at = ? WHERE id = ?', [$now, $membership['id']]);
        }
    }

    /**
     * `refresh_connection`: increment_connections unless connected?, then touch :connected_at.
     *
     * @param array{id: int, connections: int, connected_at: ?string} $membership
     */
    public function refreshConnection(array $membership): void
    {
        $now = $this->now();
        if (!$this->isConnected($membership) && 1 !== $membership['connections']) {
            // increment_connections when not connected: update!(connections: 1)
            $this->connection->executeStatement('UPDATE memberships SET connections = 1, connected_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $membership['id']]);

            return;
        }
        $this->connection->executeStatement('UPDATE memberships SET connected_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $membership['id']]);
    }

    /** Membership.disconnect_all, run when the server boots: nobody is connected yet. */
    public function disconnectAll(): int
    {
        $now = $this->clock->now();

        return (int) $this->connection->executeStatement(
            'UPDATE memberships SET connected_at = NULL, connections = 0, updated_at = ? WHERE connected_at >= ?',
            [RailsDateTimeType::format($now), RailsDateTimeType::format($this->ttlAgo())],
        );
    }

    /** The class Room's GlobalID names, for STI lookups. */
    public static function isRoomModel(string $model): bool
    {
        return 'Room' === $model || \in_array($model, Room::TYPES, true);
    }

    private function now(): string
    {
        return RailsDateTimeType::format($this->clock->now());
    }

    private function ttlAgo(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(\sprintf('-%d seconds', Membership::CONNECTION_TTL));
    }
}
