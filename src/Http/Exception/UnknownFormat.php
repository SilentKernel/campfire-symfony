<?php

declare(strict_types=1);

namespace App\Http\Exception;

use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

/** ActionController::UnknownFormat (respond_to found nothing acceptable): 406. */
final class UnknownFormat extends NotAcceptableHttpException
{
}
