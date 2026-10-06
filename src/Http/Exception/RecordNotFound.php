<?php

declare(strict_types=1);

namespace App\Http\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** ActiveRecord::RecordNotFound (`find`, `find_by!`): 404. */
final class RecordNotFound extends NotFoundHttpException
{
    public static function for(string $model, mixed $id = null): self
    {
        return new self(null === $id ? \sprintf("Couldn't find %s", $model) : \sprintf("Couldn't find %s with 'id'=%s", $model, \is_scalar($id) ? (string) $id : get_debug_type($id)));
    }
}
