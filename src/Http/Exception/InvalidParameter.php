<?php

declare(strict_types=1);

namespace App\Http\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** ActionController::BadRequest / Http::Parameters::ParseError / Rack ParameterTypeError: 400. */
final class InvalidParameter extends BadRequestHttpException
{
}
