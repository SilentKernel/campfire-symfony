<?php

declare(strict_types=1);

namespace App\Http\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** ActionController::ParameterMissing: 400. */
final class ParameterMissing extends BadRequestHttpException
{
    public function __construct(public readonly string $param)
    {
        parent::__construct(\sprintf('param is missing or the value is empty or invalid: %s', $param));
    }
}
