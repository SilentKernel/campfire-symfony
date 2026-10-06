<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Storage\Attachments;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * `message.destroy` (reference/app/models/message.rb): its boosts (`dependent: :destroy`), its
 * rich text body, its attachment (the blob purged later), the row, the room touched
 * (`belongs_to :room, touch: true`), and after commit the search index row.
 * broadcastRemove() is `message.broadcast_remove`, which callers run afterwards.
 *
 * The entity is detached rather than removed, so it keeps its id for the views and broadcasts
 * that follow.
 */
final readonly class MessageDestroyer
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private ClockInterface $clock,
        private Attachments $attachments,
        private MessageSearchIndex $searchIndex,
        private MessageBroadcasts $broadcasts,
    ) {
    }

    public function destroy(Message $message): void
    {
        $id = $message->getId();
        $room = $message->getRoom();
        $this->transactions->transaction(function () use ($message, $id, $room): void {
            $this->attachments->detach(Message::RECORD_TYPE, $id, 'attachment');
            $this->connection->delete('boosts', ['message_id' => $id]);
            $this->connection->delete('action_text_rich_texts', ['record_type' => Message::RECORD_TYPE, 'record_id' => $id, 'name' => 'body']);
            $this->connection->delete('messages', ['id' => $id]);
            $this->em->detach($message);

            $now = $this->clock->now();
            $this->connection->update('rooms', ['updated_at' => RailsDateTimeType::format($now)], ['id' => $room->getId()]);
            $room->setUpdatedAt($now);
            $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($room), 'updatedAt', $now);

            $this->transactions->afterCommit(fn () => $this->searchIndex->remove($id));
        });
    }

    /** `message.broadcast_remove` */
    public function broadcastRemove(Message $message): void
    {
        $this->broadcasts->broadcastRemove($message);
    }
}
