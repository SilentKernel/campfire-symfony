<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** `enum :status, %i[ active deactivated banned ], default: :active` (reference/app/models/user.rb). */
enum UserStatus: int
{
    case Active = 0;
    case Deactivated = 1;
    case Banned = 2;

    /** The name Rails uses for the value (`user.status`). */
    public function railsName(): string
    {
        return strtolower($this->name);
    }

    public static function fromRailsName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->railsName() === $name) {
                return $case;
            }
        }

        return null;
    }
}
