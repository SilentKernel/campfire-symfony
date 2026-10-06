<?php

declare(strict_types=1);

namespace App\Storage\Job;

use App\Job\AsyncJob;

/** ActiveStorage::AnalyzeJob */
final readonly class AnalyzeBlob implements AsyncJob
{
    public function __construct(public int $blobId)
    {
    }
}
