<?php

declare(strict_types=1);

namespace App\Storage;

/** ActiveStorage::IntegrityError: the uploaded bytes don't match the checksum. */
final class IntegrityError extends \RuntimeException
{
}
