<?php

declare(strict_types=1);

namespace App\Entity\Rooms;

use App\Entity\Room;
use Doctrine\ORM\Mapping as ORM;

/** Rooms open to all users on the account (reference/app/models/rooms/open.rb). */
#[ORM\Entity]
final class Open extends Room
{
    public const TYPE = 'Rooms::Open';

    public function getType(): string
    {
        return self::TYPE;
    }
}
