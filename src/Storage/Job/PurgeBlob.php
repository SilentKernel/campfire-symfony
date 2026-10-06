<?php

declare(strict_types=1);

namespace App\Storage\Job;

use App\Job\AsyncJob;

/** ActiveStorage::PurgeJob */
final readonly class PurgeBlob implements AsyncJob
{
    public function __construct(public int $blobId)
    {
    }
}
