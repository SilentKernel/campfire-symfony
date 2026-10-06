<?php

declare(strict_types=1);

namespace App\Job;

use App\Domain\Messages\MessageRichText;
use App\Storage\Filename;
use Doctrine\DBAL\Connection;

/**
 * What the jobs read of a message, straight from the database (jobs run in a long-lived worker;
 * nothing here goes through the entity manager).
 *
 * @phpstan-type MessageRecord array{id: int, room_id: int, room_type: string, room_name: ?string, creator_id: int, creator_name: string, body: ?string, filename: ?string}
 */
final readonly class MessageRecords
{
    public function __construct(private Connection $connection, private MessageRichText $richText)
    {
    }

    /** @return MessageRecord|null */
    public function find(int $messageId): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT messages.id, messages.room_id, rooms.type AS room_type, rooms.name AS room_name,
                       messages.creator_id, users.name AS creator_name,
                       (SELECT body FROM action_text_rich_texts WHERE record_type = 'Message' AND record_id = messages.id AND name = 'body' LIMIT 1) AS body,
                       (SELECT active_storage_blobs.filename FROM active_storage_attachments
                          INNER JOIN active_storage_blobs ON active_storage_blobs.id = active_storage_attachments.blob_id
                          WHERE active_storage_attachments.record_type = 'Message' AND active_storage_attachments.record_id = messages.id
                            AND active_storage_attachments.name = 'attachment' LIMIT 1) AS filename
                FROM messages
                INNER JOIN rooms ON rooms.id = messages.room_id
                INNER JOIN users ON users.id = messages.creator_id
                WHERE messages.id = ?
                SQL,
            [$messageId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'room_type' => (string) $row['room_type'],
            'room_name' => null === $row['room_name'] ? null : (string) $row['room_name'],
            'creator_id' => (int) $row['creator_id'],
            'creator_name' => (string) $row['creator_name'],
            'body' => null === $row['body'] ? null : (string) $row['body'],
            'filename' => null === $row['filename'] ? null : (string) $row['filename'],
        ];
    }

    /**
     * `message.plain_text_body`: `body.to_plain_text.presence || attachment&.filename&.to_s || ""`.
     *
     * @param MessageRecord $message
     */
    public function plainTextBody(array $message): string
    {
        $text = null === $message['body'] ? '' : $this->richText->toPlainText($message['body']);
        if (1 !== preg_match('/\A[[:space:]]*\z/u', $text)) {
            return $text;
        }

        return null === $message['filename'] ? '' : new Filename($message['filename'])->sanitized();
    }

    /**
     * `message.mentionees.ids`: the mentioned users who are members of the room.
     *
     * @param MessageRecord $message
     *
     * @return list<int>
     */
    public function mentioneeIds(array $message): array
    {
        $mentioned = $this->richText->mentionedUserIds($message['body']);
        if ([] === $mentioned) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.user_id WHERE memberships.room_id = ? AND users.id IN (?)',
            [$message['room_id'], $mentioned],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        ));
    }
}
