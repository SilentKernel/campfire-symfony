<?php

declare(strict_types=1);

namespace App\Http\Platform;

/**
 * Campfire's `ApplicationPlatform < PlatformAgent` (reference/app/models/application_platform.rb
 * and the platform_agent gem): what views ask about the requesting browser. Templates call it
 * through `platform()` (request attribute 'campfire.platform'), e.g. `platform().ios`.
 * Methods that raise in Rails (a nil browser, say) throw RubyError.
 */
final class ApplicationPlatform
{
    public const string ATTRIBUTE = 'campfire.platform';

    private ?UserAgent $userAgent = null;

    public function __construct(private readonly ?string $userAgentString)
    {
    }

    public function ios(): bool
    {
        return $this->match('/iPhone|iPad/');
    }

    public function android(): bool
    {
        return $this->match('/Android/');
    }

    public function mac(): bool
    {
        return $this->match('/Macintosh/');
    }

    public function chrome(): bool
    {
        return 1 === preg_match('/Chrome/', $this->requireBrowser());
    }

    public function firefox(): bool
    {
        return 1 === preg_match('/Firefox|FxiOS/', $this->requireBrowser());
    }

    public function safari(): bool
    {
        return 1 === preg_match('/Safari/', $this->requireBrowser());
    }

    public function edge(): bool
    {
        return 1 === preg_match('/Edg/', $this->requireBrowser());
    }

    /** Apple Messages link previews pretend to be the Facebook and Twitter bots. */
    public function appleMessages(): bool
    {
        return $this->match('/facebookexternalhit/i') && $this->match('/Twitterbot/i');
    }

    public function mobile(): bool
    {
        return $this->ios() || $this->android();
    }

    public function desktop(): bool
    {
        return !$this->mobile();
    }

    public function windows(): bool
    {
        return 'Windows' === $this->operatingSystem();
    }

    public function operatingSystem(): ?string
    {
        $platform = $this->userAgent()->platform();

        return match (true) {
            self::matches('/Android/', $platform) => 'Android',
            self::matches('/iPad/', $platform) => 'iPad',
            self::matches('/iPhone/', $platform) => 'iPhone',
            self::matches('/Macintosh/', $platform) => 'macOS',
            self::matches('/Windows/', $platform) => 'Windows',
            self::matches('/CrOS/', $platform) => 'ChromeOS',
            default => self::matches('/Linux/', $os = $this->userAgent()->os()) ? 'Linux' : $os,
        };
    }

    /** `browser` (delegated to the parsed user agent; may be nil). */
    public function browser(): ?string
    {
        return $this->userAgent()->browser();
    }

    public function version(): ?Version
    {
        return $this->userAgent()->version();
    }

    public function os(): ?string
    {
        return $this->userAgent()->os();
    }

    public function userAgent(): UserAgent
    {
        return $this->userAgent ??= UserAgent::parse($this->userAgentString);
    }

    private function requireBrowser(): string
    {
        return $this->browser() ?? throw RubyError::noMethod('match?');
    }

    private function match(string $pattern): bool
    {
        $string = $this->userAgentString ?? '';
        // Ruby's /i folds Unicode case (U+212A KELVIN SIGN matches "k").
        if (str_ends_with($pattern, '/i') && mb_check_encoding($string, 'UTF-8')) {
            $pattern .= 'u';
        }

        return 1 === preg_match($pattern, $string);
    }

    private static function matches(string $pattern, ?string $string): bool
    {
        return null !== $string && 1 === preg_match($pattern, $string);
    }
}
