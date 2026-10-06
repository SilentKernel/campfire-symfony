<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * The Rails controller inherits from ActionController::Base (or a framework base such as
 * ActiveStorage::BaseController), not from Campfire's ApplicationController. None of Campfire's
 * controller filters run: AllowBrowser, Authentication (authentication, deny_bots and its
 * protect_from_forgery), Authorization, BlockBannedRequests, SetCurrentRequest, SetPlatform,
 * TrackedRoomVisit, VersionHeaders.
 *
 * Rails' framework-wide `protect_from_forgery with: :exception` (load_defaults
 * `default_protect_from_forgery`) still applies unless #[SkipForgeryProtection] is present.
 * Rack middleware behaviour (session cookie, ETag, ...) is unaffected.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NotApplicationController
{
}
