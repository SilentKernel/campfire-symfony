<?php

declare(strict_types=1);

namespace App\Entity\Rooms;

use App\Entity\Room;
use Doctrine\ORM\Mapping as ORM;

/** Rooms where only some users were granted membership (reference/app/models/rooms/closed.rb). */
#[ORM\Entity]
final class Closed extends Room
{
    public const TYPE = 'Rooms::Closed';

    public function getType(): string
    {
        return self::TYPE;
    }
}
