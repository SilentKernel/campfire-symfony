<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use Doctrine\DBAL\Connection;

/**
 * Message::Searchable (reference/app/models/message/searchable.rb): the FTS5
 * `message_search_index` row of each message (rowid = message id, body = plain_text_body).
 */
final readonly class MessageSearchIndex
{
    public function __construct(private Connection $connection)
    {
    }

    /** after_create_commit :create_in_index */
    public function create(int $messageId, string $plainTextBody): void
    {
        $this->connection->executeStatement('insert into message_search_index(rowid, body) values (?, ?)', [$messageId, $plainTextBody]);
    }

    /** after_update_commit :update_in_index */
    public function update(int $messageId, string $plainTextBody): void
    {
        $this->connection->executeStatement('update message_search_index set body = ? where rowid = ?', [$plainTextBody, $messageId]);
    }

    /** after_destroy_commit :remove_from_index */
    public function remove(int $messageId): void
    {
        $this->connection->executeStatement('delete from message_search_index where rowid = ?', [$messageId]);
    }
}
