<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * Rails routes this endpoint but the controller does not define the action
 * (AbstractController::ActionNotFound), or, on a class, the controller itself does not exist
 * (ActionController::RoutingError, e.g. rooms/settings#show). Rails raises before any controller
 * callback, so the pipeline must run no filter at all (no authentication redirect, no CSRF, no
 * browser check) and let the action throw its NotFoundHttpException (404, public/404.html).
 *
 * The route must still exist: it is matched first and shadows later routes, as in Rails.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class ActionNotFound
{
}
