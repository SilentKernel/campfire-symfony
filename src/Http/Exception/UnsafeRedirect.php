<?php

declare(strict_types=1);

namespace App\Http\Exception;

/**
 * ActionController::Redirecting::UnsafeRedirectError (`action_on_open_redirect = :raise`) and
 * the path-relative redirect error: not a rescue response, so a 500.
 */
final class UnsafeRedirect extends \RuntimeException
{
}
