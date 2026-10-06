<?php

declare(strict_types=1);

namespace App\Cable\Server;

/** `identified_by :current_user`: the user as loaded when the connection opened. */
final readonly class CableUser
{
    public function __construct(public int $id, public string $name)
    {
    }
}
