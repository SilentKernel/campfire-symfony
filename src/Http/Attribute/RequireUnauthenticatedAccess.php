<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * Rails `require_unauthenticated_access` (reference/app/controllers/concerns/authentication.rb):
 * skips `require_authentication`, then runs `restore_authentication` and
 * `redirect_signed_in_user_to_root` (302 to root_url when a session cookie resolves to a user).
 * On a class it covers every action; on a method that action (`only:`).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequireUnauthenticatedAccess
{
}
