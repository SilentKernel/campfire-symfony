<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** `enum :role, %i[ member administrator bot ]` (reference/app/models/user/role.rb). */
enum UserRole: int
{
    case Member = 0;
    case Administrator = 1;
    case Bot = 2;

    /** The name Rails uses for the value (`user.role`), e.g. in params and JSON. */
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
