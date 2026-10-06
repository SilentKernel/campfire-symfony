<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The application clock is always UTC, like Rails' Time.current with the default zone. Parity runs
 * freeze it with CAMPFIRE_FROZEN_TIME (an ISO 8601 instant), as the Rust port does.
 */
final class ClockFactory
{
    public static function create(string $frozenTime = ''): ClockInterface
    {
        $utc = new \DateTimeZone('UTC');

        return '' === $frozenTime
            ? new NativeClock($utc)
            : new MockClock(new \DateTimeImmutable($frozenTime, $utc), $utc);
    }
}
