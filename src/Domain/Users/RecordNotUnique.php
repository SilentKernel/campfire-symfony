<?php

declare(strict_types=1);

namespace App\Domain\Users;

/** ActiveRecord::RecordNotUnique: a unique index refused the write (users.email_address). */
final class RecordNotUnique extends \RuntimeException
{
}
