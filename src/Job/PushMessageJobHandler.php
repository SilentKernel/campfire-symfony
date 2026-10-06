<?php

declare(strict_types=1);

namespace App\Job;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Membership;
use App\Entity\Rooms\Direct;
use App\Push\WebPushPool;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Room::MessagePusher (reference/app/models/room/message_pusher.rb): the message goes to the push
 * subscriptions of the room's members who are not looking at it (visible and disconnected
 * memberships, the creator excluded): everyone involved in everything, then the mentioned
 * members involved in mentions.
 */
#[AsMessageHandler]
final readonly class PushMessageJobHandler
{
    public function __construct(
        private Connection $connection,
        private MessageRecords $messages,
        private WebPushPool $pool,
        private ClockInterface $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(PushMessageJob $job): void
    {
        $message = $this->messages->find($job->messageId);
        if (null === $message) {
            $this->logger->notice(\sprintf('Room::PushMessageJob: message %d no longer exists', $job->messageId));

            return;
        }
        $payload = $this->payload($message);

        $this->pool->queue($payload, $this->subscriptions($message, 'everything'));
        $mentionees = $this->messages->mentioneeIds($message);
        if ([] !== $mentionees) {
            $this->pool->queue($payload, $this->subscriptions($message, 'mentions', $mentionees));
        }
    }

    /**
     * build_direct_payload / build_shared_payload.
     *
     * @param array{id: int, room_id: int, room_type: string, room_name: ?string, creator_id: int, creator_name: string, body: ?string, filename: ?string} $message
     *
     * @return array{title: string, body: string, path: string}
     */
    public function payload(array $message): array
    {
        $plainText = $this->messages->plainTextBody($message);
        $path = '/rooms/'.$message['room_id'];

        return Direct::TYPE === $message['room_type']
            ? ['title' => $message['creator_name'], 'body' => $plainText, 'path' => $path]
            : ['title' => (string) $message['room_name'], 'body' => $message['creator_name'].': '.$plainText, 'path' => $path];
    }

    /**
     * `relevant_subscriptions` merged with the involvement: Push::Subscription joined to the
     * user's memberships in the room, visible, disconnected, not the creator's; in id order
     * (find_each).
     *
     * @param array{room_id: int, creator_id: int} $message
     * @param list<int>|null                       $userIds
     *
     * @return list<array{id: int, user_id: int, endpoint: ?string, p256dh_key: ?string, auth_key: ?string}>
     */
    public function subscriptions(array $message, string $involvement, ?array $userIds = null): array
    {
        $ttlAgo = RailsDateTimeType::format($this->clock->now()->modify(\sprintf('-%d seconds', Membership::CONNECTION_TTL)));
        $sql = <<<'SQL'
            SELECT push_subscriptions.id, push_subscriptions.user_id, push_subscriptions.endpoint, push_subscriptions.p256dh_key, push_subscriptions.auth_key
            FROM push_subscriptions
            INNER JOIN users ON users.id = push_subscriptions.user_id
            INNER JOIN memberships ON memberships.user_id = users.id
            WHERE memberships.involvement != 'invisible'
              AND (memberships.connected_at IS NULL OR memberships.connected_at < ?)
              AND memberships.room_id = ?
              AND memberships.user_id != ?
              AND memberships.involvement = ?
            SQL;
        $params = [$ttlAgo, $message['room_id'], $message['creator_id'], $involvement];
        $types = [];
        if (null !== $userIds) {
            $sql .= ' AND push_subscriptions.user_id IN (?)';
            $params[] = $userIds;
            $types[4] = ArrayParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY push_subscriptions.id', $params, $types);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'endpoint' => null === $row['endpoint'] ? null : (string) $row['endpoint'],
            'p256dh_key' => null === $row['p256dh_key'] ? null : (string) $row['p256dh_key'],
            'auth_key' => null === $row['auth_key'] ? null : (string) $row['auth_key'],
        ], $rows);
    }
}
