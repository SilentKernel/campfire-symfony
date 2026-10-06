<?php

declare(strict_types=1);

namespace App\Domain\Users;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * ActiveRecord::RecordInvalid raised by `create!`/`update!`: Rails rescues it as 422
 * Unprocessable Content (ActionDispatch::ExceptionWrapper).
 */
final class RecordInvalid extends \RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 422;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
