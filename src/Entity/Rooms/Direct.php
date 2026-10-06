<?php

declare(strict_types=1);

namespace App\Entity\Rooms;

use App\Entity\Enum\Involvement;
use App\Entity\Room;
use Doctrine\ORM\Mapping as ORM;

/** Direct message rooms, one per set of users (reference/app/models/rooms/direct.rb). */
#[ORM\Entity]
final class Direct extends Room
{
    public const TYPE = 'Rooms::Direct';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getDefaultInvolvement(): Involvement
    {
        return Involvement::Everything;
    }
}
