<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * Finds the users mentions point at (`User.find(id)`, nil when there is none).
 */
interface MentionUsers
{
    public function find(int $id): ?MentionUser;
}
