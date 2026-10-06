<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Job\BotWebhookJob;
use App\Job\PushMessageJob;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The background jobs a new message enqueues: `Room::PushMessageJob.perform_later(room, message)`
 * and `Bot::WebhookJob.perform_later(bot, message)`.
 */
final readonly class MessageJobs
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /** `Room::PushMessageJob.perform_later(room, message)` */
    public function pushMessageLater(int $messageId): void
    {
        $this->bus->dispatch(new PushMessageJob($messageId));
    }

    /** `Bot::WebhookJob.perform_later(bot, message)` */
    public function deliverWebhookLater(int $botId, int $messageId): void
    {
        $this->bus->dispatch(new BotWebhookJob($botId, $messageId));
    }
}
