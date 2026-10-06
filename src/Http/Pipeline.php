<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/** Request attributes the pipeline listeners share. */
final class Pipeline
{
    /** The response came from a filter (a halted before_action) rather than the action. */
    public const string HALTED = '_campfire_halted';

    /** The response is an error page (ShowExceptions): no ETag, no cookies, no version headers. */
    public const string EXCEPTION = '_campfire_exception';

    /** X-Version/X-Rev set by the VersionHeaders filter. */
    public const string VERSION_HEADERS = '_campfire_version_headers';

    /** The Cache-Control value Rack::ETag decided. */
    public const string CACHE_CONTROL = '_campfire_cache_control';

    /** The Content-Type before Symfony's Response::prepare() appended a charset. */
    public const string CONTENT_TYPE = '_campfire_content_type';

    public static function isHalted(Request $request): bool
    {
        return true === $request->attributes->get(self::HALTED);
    }

    public static function isException(Request $request): bool
    {
        return true === $request->attributes->get(self::EXCEPTION);
    }
}
