<?php

declare(strict_types=1);

namespace App\Job;

/** Bot::WebhookJob (reference/app/jobs/bot/webhook_job.rb): `perform(bot, message)`. */
final readonly class BotWebhookJob implements AsyncJob
{
    public function __construct(public int $botId, public int $messageId)
    {
    }
}
