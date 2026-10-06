<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * Rails `allow_unauthenticated_access` (reference/app/controllers/concerns/authentication.rb):
 * skips `require_authentication`, so the action runs for anonymous visitors. A session cookie, if
 * present, is not restored by this alone. On a class it covers every action (no `only:`); on a
 * method it covers that action (`only:`).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AllowUnauthenticatedAccess
{
}
