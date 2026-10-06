<?php

declare(strict_types=1);

namespace App\Job;

/** RemoveBannedContentJob (reference/app/jobs/remove_banned_content_job.rb): `perform(user)`. */
final readonly class RemoveBannedContentJob implements AsyncJob
{
    public function __construct(public int $userId)
    {
    }
}
