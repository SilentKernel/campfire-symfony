<?php

declare(strict_types=1);

namespace App\Job;

use App\Domain\Messages\MessageCreator;
use App\Entity\Room;
use App\Entity\User;
use App\Storage\BlobService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Bot::WebhookJob → User::Bot#deliver_webhook → Webhook#deliver (reference/app/models/webhook.rb):
 * the bot's answer becomes its message in the room (text, or an attachment), broadcast like any
 * new message.
 *
 * Rails jobs are not retried (ApplicationJob has no retry_on): an endpoint that cannot be reached
 * fails the job once, rather than posting the message to the bot again.
 */
#[AsMessageHandler]
final readonly class BotWebhookJobHandler
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private MessageRecords $messages,
        private WebhookDelivery $delivery,
        private MessageCreator $creator,
        private BlobService $blobs,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(BotWebhookJob $job): void
    {
        $url = $this->connection->fetchOne('SELECT url FROM webhooks WHERE user_id = ? LIMIT 1', [$job->botId]);
        $bot = $this->em->find(User::class, $job->botId);
        $message = $this->messages->find($job->messageId);
        if (!\is_string($url) || null === $bot || null === $message) {
            $this->logger->notice(\sprintf('Bot::WebhookJob: bot %d, its webhook or message %d no longer exists', $job->botId, $job->messageId));

            return;
        }

        try {
            $reply = $this->delivery->deliver($url, $message, ['id' => $bot->getId(), 'name' => $bot->getName(), 'bot_key' => $bot->botKey()]);
        } catch (TransportExceptionInterface $error) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Webhook delivery to %s failed: %s', $url, $error->getMessage()), 0, $error);
        }
        if (null === $reply) {
            return;
        }

        $room = $this->em->find(Room::class, $message['room_id']);
        if (null === $room) {
            return;
        }
        if (isset($reply['text'])) {
            // receive_text_reply_to: room.messages.create!(body: text, creator: user).broadcast_create
            $this->creator->create($room, $bot, $reply['text'], deliverWebhooks: false);
        } else {
            // receive_attachment_reply_to: create_with_attachment!(attachment:, creator:).broadcast_create
            $blob = $this->blobs->createFromBytes($reply['attachment'], $reply['filename'], $reply['content_type']);
            $this->creator->create($room, $bot, null, $blob, deliverWebhooks: false);
        }
        $this->em->clear();
    }
}
