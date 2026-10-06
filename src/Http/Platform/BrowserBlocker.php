<?php

declare(strict_types=1);

namespace App\Http\Platform;

/**
 * ActionController::AllowBrowser::BrowserBlocker (actionpack 8.2) with Campfire's
 * `AllowBrowser::VERSIONS` (reference/app/controllers/concerns/allow_browser.rb).
 */
final class BrowserBlocker
{
    /** `{ safari: 17.2, chrome: 120, firefox: 121, opera: 104, ie: false }` */
    public const array VERSIONS = ['safari' => '17.2', 'chrome' => '120', 'firefox' => '121', 'opera' => '104', 'ie' => false];

    /**
     * `blocked?`: a reported version below the minimum of a guarded browser, unless a bot.
     *
     * @param array<string, string|false|null> $versions
     */
    public static function blocked(?string $userAgentString, array $versions = self::VERSIONS): bool
    {
        if (!self::present($userAgentString)) {
            return false;
        }
        $agent = UserAgent::parse($userAgentString);
        $version = $agent->version();
        if (null === $version || !self::present((string) $version)) {
            return false;
        }

        $browser = $agent->browser() ?? throw RubyError::noMethod('downcase');
        $name = mb_strtolower($browser);
        $name = 'internet explorer' === $name ? 'ie' : $name;
        if (!\array_key_exists($name, $versions) || null === $versions[$name]) {
            return false;
        }

        $minimum = $versions[$name];
        $below = false === $minimum || $version->lessThan((string) $minimum);

        return $below && !$agent->isBot();
    }

    /** ActiveSupport `present?` for strings: not only (Unicode) whitespace. */
    private static function present(?string $string): bool
    {
        return null !== $string && 1 !== preg_match('/\A[[:space:]]*\z/u', $string);
    }
}
