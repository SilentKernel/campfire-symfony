<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * `time.to_fs(:epoch)` (reference/config/initializers/time_formats.rb):
 * `(time.to_f * 1000).to_i`, float arithmetic included.
 */
final class Epoch
{
    public static function milliseconds(\DateTimeInterface $time): int
    {
        return (int) ((float) $time->format('U.u') * 1000);
    }
}
