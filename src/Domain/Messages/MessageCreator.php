<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\ActiveStorage\Attachment;
use App\Entity\ActiveStorage\Blob;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Storage\Attachments;
use App\Storage\BlobService;
use App\Storage\Filename;
use App\Storage\MessageAttachmentProcessor;
use App\Storage\RecordToucher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * `room.messages.create_with_attachment!(attributes)` (reference/app/models/message.rb and its
 * concerns) followed by what MessagesController#create does with the new message:
 *
 * 1. in one transaction: the message (client_message_id defaults to a random UUID), its rich text
 *    body (canonicalized, touching the message), its attachment (touching the message), and the
 *    room touched (`belongs_to :room, touch: true`);
 * 2. after commit: the search index row (plain_text_body) and Room#receive (unread memberships,
 *    push notifications job);
 * 3. `process_attachment`, synchronously: analysis and the thumbnail or video preview;
 * 4. `broadcast_create` (the message appended to the room, an unread ping to every member);
 * 5. `deliver_webhooks_to_bots`, unless $deliverWebhooks is false: a bot's webhook reply
 *    (Webhook#receive_text_reply_to / receive_attachment_reply_to in reference/app/models/webhook.rb)
 *    is created and broadcast, but notifies no bot.
 */
final readonly class MessageCreator
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private ClockInterface $clock,
        private MessageRichText $richText,
        private MessageSearchIndex $searchIndex,
        private MessageBroadcasts $broadcasts,
        private MessageJobs $jobs,
        private BlobService $blobs,
        private Attachments $attachments,
        private MessageAttachmentProcessor $attachmentProcessor,
        private RecordToucher $toucher,
        private Mentionees $mentionees,
    ) {
    }

    public function create(Room $room, User $creator, ?string $bodyHtml, UploadedFile|Blob|null $attachment = null, ?string $clientMessageId = null, bool $deliverWebhooks = true): Message
    {
        $body = null !== $bodyHtml ? $this->richText->canonicalize($bodyHtml) : null;
        [$message, $attached] = $this->transactions->transaction(function () use ($room, $creator, $body, $attachment, $clientMessageId): array {
            $message = new Message($room, $creator, null === $clientMessageId || '' === $clientMessageId ? Uuid::v4()->toRfc4122() : $clientMessageId);
            $this->em->persist($message);
            $this->em->flush();
            $id = $message->getId();

            if (null !== $body) {
                $this->insertBody($id, $body);
            }
            $attached = null;
            if (null !== $attachment) {
                $blob = $attachment instanceof UploadedFile ? $this->blobs->createFromUpload($attachment) : $attachment;
                $attached = $this->attachments->attach(Message::RECORD_TYPE, $id, 'attachment', $blob);
            }
            if (null !== $body) {
                // The rich text and the attachment touch the message, which touches the room.
                $this->toucher->touch(Message::RECORD_TYPE, $id);
            } elseif (null === $attached) {
                $this->touchRoom($room);
            }

            $plainText = $this->plainTextBody($body, $attached);
            $createdAt = $message->getCreatedAt();
            $this->transactions->afterCommit(function () use ($message, $id, $plainText, $createdAt): void {
                $this->searchIndex->create($id, $plainText);
                $this->receive($message, $createdAt);
            });

            return [$message, $attached];
        });

        if ($attached instanceof Attachment) {
            $this->attachmentProcessor->process($attached);
        }

        $this->broadcasts->broadcastCreate($message);
        if ($deliverWebhooks) {
            $this->deliverWebhooksToBots($message, $body);
        }

        return $message;
    }

    /**
     * Room#receive: unread for the visible, disconnected memberships of everyone but the
     * creator, then `push_later`.
     */
    private function receive(Message $message, \DateTimeImmutable $createdAt): void
    {
        $now = $this->clock->now();
        $this->connection->executeStatement(
            "UPDATE memberships SET unread_at = ?, updated_at = ? WHERE room_id = ? AND involvement != 'invisible'"
            .' AND (connected_at IS NULL OR connected_at < ?) AND user_id != ?',
            [
                RailsDateTimeType::format($createdAt),
                RailsDateTimeType::format($now),
                $message->getRoom()->getId(),
                RailsDateTimeType::format($now->modify('-60 seconds')),
                $message->getCreator()->getId(),
            ],
        );
        $this->jobs->pushMessageLater($message->getId());
    }

    /**
     * MessagesController#deliver_webhooks_to_bots: every active bot in a direct room, else every
     * mentioned active bot of the room, except the message's creator; only bots with a webhook.
     */
    private function deliverWebhooksToBots(Message $message, ?string $body): void
    {
        $room = $message->getRoom();
        $creatorId = $message->getCreator()->getId();
        $botIds = $room->isDirect()
            ? $this->mentionees->activeBotIdsInRoom($room->getId())
            : $this->mentionees->activeBotIdsAmong($room->getId(), $this->richText->mentionedUserIds($body));
        foreach ($botIds as $botId) {
            if ($botId !== $creatorId && $this->mentionees->hasWebhook($botId)) {
                $this->jobs->deliverWebhookLater($botId, $message->getId());
            }
        }
    }

    /** `plain_text_body` */
    private function plainTextBody(?string $body, ?Attachment $attachment): string
    {
        $text = null !== $body ? $this->richText->toPlainText($body) : '';
        if (1 !== preg_match('/\A[[:space:]]*\z/u', $text)) {
            return $text;
        }

        return null !== $attachment ? (new Filename($attachment->getBlob()->getFilename()))->sanitized() : '';
    }

    private function insertBody(int $messageId, string $body): void
    {
        $now = RailsDateTimeType::format($this->clock->now());
        $this->connection->insert('action_text_rich_texts', [
            'name' => 'body',
            'body' => $body,
            'record_type' => Message::RECORD_TYPE,
            'record_id' => $messageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** `belongs_to :room, touch: true`, keeping the loaded room in step without dirtying it. */
    private function touchRoom(Room $room): void
    {
        $now = $this->clock->now();
        $this->connection->update('rooms', ['updated_at' => RailsDateTimeType::format($now)], ['id' => $room->getId()]);
        $room->setUpdatedAt($now);
        $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($room), 'updatedAt', $now);
    }
}
