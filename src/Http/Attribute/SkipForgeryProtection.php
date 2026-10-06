<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * Rails `skip_forgery_protection`: no CSRF token verification for non-GET/HEAD requests.
 * Used by PwaController, ActiveStorage::DiskController and the Action Mailbox ingresses.
 * Every other controller verifies the token (Campfire's Authentication concern, or the
 * framework-wide `default_protect_from_forgery` for ActionController::Base subclasses).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class SkipForgeryProtection
{
}
