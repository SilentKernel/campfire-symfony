<?php

declare(strict_types=1);

namespace App\Job;

/** Room::PushMessageJob (reference/app/jobs/room/push_message_job.rb): `perform(room, message)`. */
final readonly class PushMessageJob implements AsyncJob
{
    public function __construct(public int $messageId)
    {
    }
}
