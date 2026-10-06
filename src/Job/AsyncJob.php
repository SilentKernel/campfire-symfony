<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Marker for Messenger messages that run in the background job worker, the way Rails runs
 * ActiveJob classes on Resque (reference/app/jobs). Everything implementing it is routed to the
 * `async` transport (config/packages/messenger.yaml).
 */
interface AsyncJob
{
}
