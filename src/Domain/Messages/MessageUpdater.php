<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Storage\Attachments;
use App\Storage\Filename;
use App\Storage\RecordToucher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * `@message.update!(body:)` then MessagesController#update's broadcast: the rich text body is
 * replaced (canonicalized), touching the message and its room; after commit the search index row
 * follows the new plain text; then the presentation is replaced in the room
 * (`broadcast_replace_to ... partial: "messages/presentation"`).
 */
final readonly class MessageUpdater
{
    public function __construct(
        private Connection $connection,
        private Transactions $transactions,
        private ClockInterface $clock,
        private MessageRichText $richText,
        private MessageSearchIndex $searchIndex,
        private MessageBroadcasts $broadcasts,
        private Attachments $attachments,
        private RecordToucher $toucher,
    ) {
    }

    public function update(Message $message, string $bodyHtml, bool $broadcast = true): void
    {
        $id = $message->getId();
        $body = $this->richText->canonicalize($bodyHtml);
        $this->transactions->transaction(function () use ($id, $body): void {
            $now = RailsDateTimeType::format($this->clock->now());
            $updated = $this->connection->executeStatement(
                "UPDATE action_text_rich_texts SET body = ?, updated_at = ? WHERE record_type = 'Message' AND record_id = ? AND name = 'body'",
                [$body, $now, $id],
            );
            if (0 === $updated) {
                $this->connection->insert('action_text_rich_texts', [
                    'name' => 'body', 'body' => $body, 'record_type' => Message::RECORD_TYPE, 'record_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->toucher->touch(Message::RECORD_TYPE, $id);

            $plainText = $this->plainTextBody($id, $body);
            $this->transactions->afterCommit(fn () => $this->searchIndex->update($id, $plainText));
        });

        if ($broadcast) {
            $this->broadcasts->broadcastReplace($message);
        }
    }

    /** The broadcast of an update that changed nothing in the body. */
    public function broadcastReplace(Message $message): void
    {
        $this->broadcasts->broadcastReplace($message);
    }

    private function plainTextBody(int $id, string $body): string
    {
        $text = $this->richText->toPlainText($body);
        if (1 !== preg_match('/\A[[:space:]]*\z/u', $text)) {
            return $text;
        }
        $attachment = $this->attachments->find(Message::RECORD_TYPE, $id, 'attachment');

        return null !== $attachment ? (new Filename($attachment->getBlob()->getFilename()))->sanitized() : '';
    }
}
