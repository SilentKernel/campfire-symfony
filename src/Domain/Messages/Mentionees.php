<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The bots a message is delivered to (MessagesController#bots_eligible_for_webhook):
 * `room.users.active_bots` for a direct room, else `message.mentionees.active_bots`
 * (Message::Mentionee: the mentioned users who are members of the room).
 */
final readonly class Mentionees
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<int> */
    public function activeBotIdsInRoom(int $roomId): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.user_id WHERE memberships.room_id = ? AND users.status = ? AND users.role = ?',
            [$roomId, UserStatus::Active->value, UserRole::Bot->value],
        ));
    }

    /**
     * @param list<int> $userIds the mentioned users
     *
     * @return list<int>
     */
    public function activeBotIdsAmong(int $roomId, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.user_id WHERE memberships.room_id = ? AND users.id IN (?) AND users.status = ? AND users.role = ?',
            [$roomId, array_values(array_unique($userIds)), UserStatus::Active->value, UserRole::Bot->value],
            [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER],
        ));
    }

    /** `deliver_webhook_later` only enqueues for a bot with a webhook. */
    public function hasWebhook(int $botId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM webhooks WHERE user_id = ? LIMIT 1', [$botId]);
    }
}
