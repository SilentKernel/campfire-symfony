<?php

declare(strict_types=1);

namespace App\Http\Platform;

/**
 * A port of the useragent gem (0.16.11), which Rails' `allow_browser` and platform_agent use:
 * `UserAgent.parse` splits the header into products, and the first class of
 * `UserAgent::Browsers::ALL` whose `extend?` accepts them answers `browser`, `version`,
 * `platform`, `os`, `bot?` and `mobile?`. The class is kept as $kind and each method dispatches
 * on it, mirroring the gem's subclass overrides (read the gem in the reference image:
 * `bundle show useragent`). Where the gem raises, RubyError is thrown.
 *
 * Regexps follow Ruby's: `\s` and `\d` are ASCII, `^`/`$` are line anchors (a header has no
 * newline, so they anchor the string).
 */
final class UserAgent
{
    public const string DEFAULT_USER_AGENT = 'Mozilla/4.0 (compatible)';

    private const string MATCHER = '/\A[\'"]*([^\/ \t\r\n\f\v]+)\/?([^ \t\r\n\f\v,]*)([ \t\r\n\f\v]\(([^\)]*)\)|,gzip\(gfe\))?/';

    /** `UserAgent::Browsers::ALL`, in detection order. */
    private const array ALL = [
        'Edge', 'InternetExplorer', 'Opera', 'WechatBrowser', 'Vivaldi', 'Chrome', 'ITunes',
        'PlayStation', 'PodcastAddict', 'Webkit', 'Gecko', 'WindowsMediaPlayer', 'AppleCoreMedia',
        'Libavformat',
    ];

    private const array WEBKIT_BUILD_VERSIONS = [
        '85.7' => '1.0', '85.8.5' => '1.0.3', '85.8.2' => '1.0.3', '124' => '1.2', '125.2' => '1.2.2',
        '125.4' => '1.2.3', '125.5.5' => '1.2.4', '125.5.6' => '1.2.4', '125.5.7' => '1.2.4',
        '312.1.1' => '1.3', '312.1' => '1.3', '312.5' => '1.3.1', '312.5.1' => '1.3.1', '312.5.2' => '1.3.1',
        '312.8' => '1.3.2', '312.8.1' => '1.3.2', '412' => '2.0', '412.6' => '2.0', '412.6.2' => '2.0',
        '412.7' => '2.0.1', '416.11' => '2.0.2', '416.12' => '2.0.2', '417.9' => '2.0.3', '418' => '2.0.3',
        '418.8' => '2.0.4', '418.9' => '2.0.4', '418.9.1' => '2.0.4', '419' => '2.0.4', '425.13' => '2.2',
        '534.52.7' => '5.1.2',
    ];

    /** @param list<Product> $products */
    private function __construct(public readonly string $kind, public readonly array $products)
    {
    }

    /** `UserAgent.parse(string)` */
    public static function parse(?string $string): self
    {
        if (null === $string || '' === self::strip($string)) {
            $string = self::DEFAULT_USER_AGENT;
        }

        $products = [];
        while (preg_match(self::MATCHER, $string, $m, \PREG_UNMATCHED_AS_NULL)) {
            $products[] = new Product((string) $m[1], $m[2], Product::parseComment($m[4] ?? null));
            $string = self::strip(substr($string, \strlen($m[0])));
        }

        foreach (self::ALL as $kind) {
            if (self::extends($kind, $products)) {
                return new self($kind, $products);
            }
        }

        return new self('Base', $products);
    }

    // -----------------------------------------------------------------------------------------
    // Detection (`self.extend?(agent)`)

    /** @param list<Product> $products */
    private static function extends(string $kind, array $products): bool
    {
        $first = $products[0] ?? null;
        $last = [] === $products ? null : $products[\count($products) - 1];
        $any = static function (callable $predicate) use ($products): bool {
            foreach ($products as $product) {
                if ($predicate($product)) {
                    return true;
                }
            }

            return false;
        };

        return match ($kind) {
            'Edge' => 'Edge' === $last?->product,
            'InternetExplorer' => null !== $first && null !== $first->comment
                && (self::matches('/MSIE/', $first->comment[1] ?? null) || self::matches('/Trident.+rv:/', implode('; ', $first->comment))),
            'Opera' => 'Opera' === $first?->product || 'OPR' === $last?->product,
            'WechatBrowser' => $any(static fn (Product $p): bool => self::matchesI('/MicroMessenger/i', $p->product)),
            'Vivaldi' => $any(static fn (Product $p): bool => 'Vivaldi' === $p->product),
            'Chrome' => $any(static fn (Product $p): bool => \in_array($p->product, ['Chrome', 'CriOS'], true)),
            'ITunes' => $any(static fn (Product $p): bool => 'iTunes' === $p->product),
            'PlayStation' => null !== $first && null !== $first->comment && [] !== $first->comment
                && (str_contains($first->comment[0], 'PLAYSTATION 3') || str_contains($first->comment[0], 'PlayStation Vita') || str_contains($first->comment[0], 'PlayStation 4')),
            'PodcastAddict' => \count($products) >= 3 && 'Podcast' === $products[0]->product && 'Addict' === $products[1]->product && '-' === $products[2]->product,
            'Webkit' => $any(static fn (Product $p): bool => self::matchesI('/\AAppleWebKit\z/i', $p->product)
                || null !== $p->detectComment(static fn (string $c): bool => null !== self::matchI('/\A(?<webkit>AppleWebKit)\/(?<version>[\d\.]+)/i', $c))),
            'Gecko' => 'Mozilla' === $first?->product,
            // `agent.version` is the first product's version.
            'WindowsMediaPlayer' => $any(static fn (Product $p): bool => \in_array($p->product, ['NSPlayer', 'Windows-Media-Player', 'WMFSDK'], true)
                && !\in_array((string) $first?->version, ['4.1.0.3856', '7.10.0.3059', '7.0.0.1956'], true)),
            'AppleCoreMedia' => $any(static fn (Product $p): bool => 'AppleCoreMedia' === $p->product),
            'Libavformat' => $any(static fn (Product $p): bool => 'Lavf' === $p->product || ('NSPlayer' === $p->product && '4.1.0.3856' === (string) $first?->version)),
            default => false,
        };
    }

    // -----------------------------------------------------------------------------------------
    // Public interface

    /** `browser` (nil possible). */
    public function browser(): ?string
    {
        return match ($this->kind) {
            'Edge' => 'Edge',
            'InternetExplorer' => 'Internet Explorer',
            'Opera' => 'Opera',
            'WechatBrowser' => 'Wechat Browser',
            'Vivaldi' => 'Vivaldi',
            'Chrome' => null !== $this->detectProduct('Iron') ? 'Iron' : 'Chrome',
            'ITunes' => 'iTunes',
            'PlayStation' => $this->playStationBrowser(),
            'PodcastAddict' => 'Podcast Addict',
            'Webkit' => $this->webkitBrowser(),
            'Gecko' => $this->geckoBrowser(),
            'WindowsMediaPlayer' => 'Windows Media Player',
            'AppleCoreMedia' => 'AppleCoreMedia',
            'Libavformat' => 'libavformat',
            default => $this->application()?->product,
        };
    }

    /** `version` (nil possible). */
    public function version(): ?Version
    {
        switch ($this->kind) {
            case 'Edge':
            case 'Vivaldi':
                return ($this->last() ?? throw RubyError::noMethod('version'))->version;
            case 'InternetExplorer':
                $joined = implode('; ', $this->requireComment($this->application()));

                return new Version(preg_match('/(MSIE[ \t\r\n\f\v]|rv:)([\d\.]+)/', $joined, $m) ? $m[2] : '');
            case 'Opera':
                if ($this->operaMini()) {
                    $comment = $this->application()?->detectComment(static fn (string $c): bool => str_contains($c, 'Opera Mini'));

                    return new Version(null !== $comment && preg_match('/Opera Mini\/([\d\.]+)/', $comment, $m) ? $m[1] : '');
                }
                if (null !== $product = $this->detectProduct('Version')) {
                    return $product->version;
                }
                if (null !== $product = $this->detectProduct('OPR')) {
                    return $product->version;
                }

                return $this->baseVersion();
            case 'WechatBrowser':
                return ($this->detectProduct('MicroMessenger') ?? throw RubyError::noMethod('version'))->version;
            case 'Chrome':
                return ($this->detectProduct('CriOs') ?? $this->detectProduct('chrome') ?? throw RubyError::noMethod('version'))->version;
            case 'ITunes':
                return ($this->detectProduct('iTunes') ?? throw RubyError::noMethod('version'))->version;
            case 'PlayStation':
                return $this->playStationVersion();
            case 'PodcastAddict':
                return null;
            case 'Webkit':
                return $this->webkitVersion();
            case 'Gecko':
                $version = ($this->detectProduct((string) $this->geckoBrowser()) ?? throw RubyError::noMethod('version'))->version;

                return $version->isNil() ? $this->baseVersion() : $version;
            case 'Libavformat':
                return null !== $this->detectProduct('NSPlayer') ? null : $this->baseVersion();
            default:
                return $this->baseVersion();
        }
    }

    /** `platform` */
    public function platform(): ?string
    {
        $comment = $this->application()?->comment;
        $first = $comment[0] ?? null;
        $any = static fn (string $needle): bool => null !== $comment && [] !== array_filter($comment, static fn (string $c): bool => str_contains($c, $needle));

        switch ($this->kind) {
            case 'Edge':
            case 'InternetExplorer':
            case 'WindowsMediaPlayer':
                return 'Windows';
            case 'Opera':
                if (null === $comment) {
                    return null;
                }

                return self::matches('/Windows/', $first) ? 'Windows' : $first;
            case 'AppleCoreMedia':
                if (null === $this->application()) {
                    return null;
                }
                $this->requireComment($this->application());

                return self::matches('/Windows/', $first) ? 'Windows' : $first;
            case 'WechatBrowser':
                if (null === $comment) {
                    return null;
                }
                if (self::matches('/iPhone/', $first)) {
                    return 'iPhone';
                }

                return $any('Android') ? 'Android' : $first;
            case 'Chrome':
            case 'Vivaldi':
                if (null === $this->application()) {
                    return null;
                }
                if (self::matches('/Windows/', $first)) {
                    return 'Windows';
                }
                if ($any('CrOS')) {
                    return 'ChromeOS';
                }

                return $any('Android') ? 'Android' : $first;
            case 'Webkit':
            case 'ITunes':
                return $this->webkitPlatform();
            case 'PlayStation':
                return $this->playStationPlatform();
            case 'PodcastAddict':
                $os = $this->podcastAddictOs() ?? throw RubyError::noMethod('include?');

                return str_contains($os, 'Android') ? 'Android' : null;
            case 'Gecko':
                if (null === $comment) {
                    return null;
                }
                if ('compatible' === $first || 'Mobile' === $first) {
                    return null;
                }

                return self::matches('/^Windows /', $first) ? 'Windows' : $first;
            default:
                return null;
        }
    }

    /** `os` */
    public function os(): ?string
    {
        switch ($this->kind) {
            case 'Edge':
                $match = $this->detectCommentMatch('/Windows NT [\d\.]+|Windows Phone (OS )?[\d\.]+/');

                return OperatingSystems::normalize($match ?? '');
            case 'InternetExplorer':
                $joined = implode('; ', $this->requireComment($this->application()));

                return OperatingSystems::normalize(preg_match('/Windows NT [\d\.]+|Windows Phone (OS )?[\d\.]+/', $joined, $m) ? $m[0] : '');
            case 'Opera':
                $comment = $this->application()?->comment;
                if (null === $comment) {
                    return null;
                }

                return self::matches('/Windows/', $comment[0] ?? null) ? OperatingSystems::normalize($comment[0]) : ($comment[1] ?? null);
            case 'WechatBrowser':
                if (null === $this->application()?->comment) {
                    return null;
                }

                return $this->chromeOs();
            case 'Chrome':
            case 'Vivaldi':
            case 'AppleCoreMedia':
                return null === $this->application() ? null : $this->chromeOs();
            case 'Webkit':
                return $this->webkitOs();
            case 'ITunes':
                return $this->iTunesOs();
            case 'PlayStation':
                return $this->playStationOs();
            case 'PodcastAddict':
                return $this->podcastAddictOs();
            case 'Gecko':
                return $this->geckoOs();
            case 'WindowsMediaPlayer':
                return $this->windowsMediaPlayerOs();
            default:
                return null;
        }
    }

    /** `bot?` */
    public function isBot(): bool
    {
        $application = $this->application();
        if (null === $application) {
            return true;
        }
        if (null !== $this->detectCommentMatch('/bot/i')) {
            return true;
        }
        if (null !== $this->detectProduct('Chrome-Lighthouse')) {
            return true;
        }

        return str_contains($application->product, 'bot');
    }

    /** `mobile?` */
    public function isMobile(): bool
    {
        switch ($this->kind) {
            case 'Opera':
                return $this->operaMini();
            case 'PlayStation':
                return 'PlayStation Vita' === $this->playStationPlatform();
            case 'PodcastAddict':
                return true;
            case 'WindowsMediaPlayer':
                return \in_array($this->windowsMediaPlayerOs(), ['Windows Phone 8', 'Windows Phone 8.1'], true);
        }

        if (null !== $this->detectProduct('Mobile') || null !== $this->detectComment('Mobile')) {
            return true;
        }
        if (self::matches('/Android/', $this->os())) {
            return true;
        }

        return null !== $this->application()?->detectComment(static fn (string $c): bool => self::matches('/^IEMobile/', $c));
    }

    // -----------------------------------------------------------------------------------------
    // Base helpers

    private function first(): ?Product
    {
        return $this->products[0] ?? null;
    }

    private function last(): ?Product
    {
        return [] === $this->products ? null : $this->products[\count($this->products) - 1];
    }

    /** `application`: the first product, or (WebKit family) the first with a non-empty comment. */
    private function application(): ?Product
    {
        if (\in_array($this->kind, ['Chrome', 'Vivaldi', 'Webkit', 'ITunes', 'AppleCoreMedia'], true)) {
            foreach ($this->products as $product) {
                if (null !== $product->comment && [] !== $product->comment) {
                    return $product;
                }
            }

            return null;
        }

        return $this->first();
    }

    private function baseVersion(): ?Version
    {
        return $this->application()?->version;
    }

    /** `detect_product`: case-insensitive product lookup (also `respond_to?`/`method_missing`). */
    private function detectProduct(string $name): ?Product
    {
        $name = mb_strtolower($name);
        foreach ($this->products as $product) {
            if (mb_strtolower($product->product) === $name) {
                return $product;
            }
        }

        return null;
    }

    private function detectComment(string $comment): ?Product
    {
        foreach ($this->products as $product) {
            if (null !== $product->detectComment(static fn (string $c): bool => $c === $comment)) {
                return $product;
            }
        }

        return null;
    }

    /** `detect_comment_match(regexp)`: the first comment match (its text), or null. */
    private function detectCommentMatch(string $regexp): ?string
    {
        foreach ($this->products as $product) {
            foreach ($product->comment ?? [] as $comment) {
                if (null !== $m = self::matchI($regexp, $comment)) {
                    return $m[0];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function requireComment(?Product $product): array
    {
        if (null === $product || null === $product->comment) {
            throw RubyError::noMethod('comment');
        }

        return $product->comment;
    }

    // -----------------------------------------------------------------------------------------
    // Per-browser pieces

    /** Chrome/Vivaldi/AppleCoreMedia/Wechat `os` with the application's comment. */
    private function chromeOs(): ?string
    {
        $comment = $this->requireComment($this->application());
        if (self::matches('/Windows NT/', $comment[0] ?? null)) {
            return OperatingSystems::normalize($comment[0]);
        }
        if (!isset($comment[2])) {
            return OperatingSystems::normalize($comment[1] ?? null);
        }
        if (self::matches('/Android/', $comment[1] ?? null)) {
            return OperatingSystems::normalize($comment[1]);
        }

        return OperatingSystems::normalize($comment[2]);
    }

    private function operaMini(): bool
    {
        // `/Opera Mini/ === application` matches the product's to_str.
        $application = $this->application();

        return null !== $application && str_contains((string) $application, 'Opera Mini');
    }

    private function playStationBrowser(): ?string
    {
        $first = $this->requireComment($this->application())[0] ?? throw RubyError::noMethod('include?');
        if (str_contains($first, 'PLAYSTATION 3')) {
            return 'PS3 Internet Browser';
        }
        if ('Silk' === $this->last()?->product) {
            return 'Silk';
        }

        return str_contains($first, 'PlayStation 4') ? 'PS4 Internet Browser' : null;
    }

    private function playStationOs(): string
    {
        return implode(' ', $this->requireComment($this->application()));
    }

    private function playStationPlatform(): ?string
    {
        $os = $this->playStationOs();

        return match (true) {
            str_contains($os, 'PLAYSTATION 3') => 'PlayStation 3',
            str_contains($os, 'PlayStation 4') => 'PlayStation 4',
            str_contains($os, 'PlayStation Vita') => 'PlayStation Vita',
            default => null,
        };
    }

    private function playStationVersion(): ?Version
    {
        if ('Silk' === $this->playStationBrowser()) {
            return $this->last()?->version;
        }
        $after = function (string $marker): Version {
            $parts = explode($marker, $this->playStationOs());
            while ([] !== $parts && '' === end($parts)) {
                array_pop($parts);
            }

            return new Version([] === $parts ? '' : end($parts));
        };

        return match ($this->playStationPlatform()) {
            'PlayStation 3' => $after('PLAYSTATION 3 '),
            'PlayStation 4' => $after('PlayStation 4 '),
            'PlayStation Vita' => $after('PlayStation Vita '),
            default => null,
        };
    }

    private function podcastAddictOs(): ?string
    {
        $device = $this->products[3] ?? null;
        if (null === $device) {
            return null;
        }
        if ('Dalvik' !== $device->product && 'Mozilla' !== $device->product) {
            return null;
        }
        $comment = $device->comment ?? throw RubyError::noMethod('length');
        if (\count($comment) > 3) {
            return $comment[2];
        }

        return 3 === \count($comment) ? 'Android' : null;
    }

    private function webkitBrowser(): string
    {
        if (self::matches('/Android/', $this->webkitOs())) {
            return 'Android';
        }

        return 'BlackBerry' === $this->webkitPlatform() ? 'BlackBerry' : 'Safari';
    }

    private function webkitPlatform(): ?string
    {
        $application = $this->application();
        if (null === $application) {
            return null;
        }
        $comment = $this->requireComment($application);
        $first = $comment[0] ?? null;
        if (self::matches('/Windows/', $first)) {
            return 'Windows';
        }
        if ('BB10' === $first) {
            return 'BlackBerry';
        }
        foreach ($comment as $c) {
            if (str_contains($c, 'Android')) {
                return 'Android';
            }
        }

        return $first;
    }

    private function webkitOs(): ?string
    {
        $application = $this->application();
        if (null === $application) {
            return null;
        }
        $comment = $this->requireComment($application);
        if (self::matches('/Windows NT/', $comment[0] ?? null)) {
            return OperatingSystems::normalize($comment[0]);
        }
        if (!isset($comment[2])) {
            return OperatingSystems::normalize($comment[1] ?? null);
        }
        if (self::matches('/Android/', $comment[1] ?? null)) {
            return OperatingSystems::normalize($comment[1]);
        }
        foreach ($comment as $c) {
            if (preg_match(OperatingSystems::IOS_VERSION_REGEX, $c)) {
                return OperatingSystems::normalize($c);
            }
        }

        return OperatingSystems::normalize($comment[2]);
    }

    private function webkitVersion(): Version
    {
        if (null !== $product = $this->detectProduct('Version')) {
            return $product->version;
        }
        if (preg_match('/iOS ([\d\.]+)/', (string) $this->webkitOs(), $m) && 'Safari' === $this->webkitBrowser()) {
            return new Version(strtr($m[1], '_', '.'));
        }

        return new Version(self::WEBKIT_BUILD_VERSIONS[(string) $this->webkit()?->version] ?? '');
    }

    /** `webkit`: the AppleWebKit product, or one built from a comment. */
    private function webkit(): ?Product
    {
        foreach ($this->products as $product) {
            if (self::matchesI('/\AAppleWebKit\z/i', $product->product)) {
                return $product;
            }
        }
        foreach ($this->products as $product) {
            foreach ($product->comment ?? [] as $comment) {
                if (null !== $m = self::matchI('/\A(?<webkit>AppleWebKit)\/(?<version>[\d\.]+)/i', $comment)) {
                    return new Product($m['webkit'], $m['version']);
                }
            }
        }

        return null;
    }

    private function iTunesOs(): ?string
    {
        $application = $this->application();
        if (null !== $application && self::matches('/Windows/', $this->requireComment($application)[0] ?? null)) {
            $fullOs = $this->iTunesFullOs();

            return match (true) {
                self::matches('/Windows 8\.1/', $fullOs) => 'Windows 8.1',
                self::matches('/Windows 8/', $fullOs) => 'Windows 8',
                self::matches('/Windows 7/', $fullOs) => 'Windows 7',
                self::matches('/Windows Vista/', $fullOs) => 'Windows Vista',
                self::matches('/Windows XP/', $fullOs) => 'Windows XP',
                default => 'Windows',
            };
        }

        return $this->webkitOs();
    }

    private function iTunesFullOs(): ?string
    {
        $comment = $this->application()?->comment;
        if (null === $comment || \count($comment) <= 1) {
            return null;
        }
        $fullOs = $comment[1];

        return preg_match('/\(Build [0-9][0-9][0-9][0-9]\z/', $fullOs) ? $fullOs.')' : $fullOs;
    }

    private function geckoBrowser(): ?string
    {
        foreach (['PaleMoon', 'Firefox', 'Camino', 'Iceweasel', 'Seamonkey'] as $browser) {
            if (null !== $this->detectProduct($browser)) {
                return $browser;
            }
        }

        return $this->application()?->product;
    }

    private function geckoOs(): ?string
    {
        $comment = $this->application()?->comment;
        if (null === $comment) {
            return null;
        }
        $first = $comment[0] ?? null;
        if ('U' === ($comment[1] ?? null)) {
            $i = 2;
        } elseif (self::matches('/^Windows /', $first) || self::matches('/^Android/', $first)) {
            $i = 0;
        } elseif ('Mobile' === $first) {
            return null;
        } else {
            $i = 1;
        }

        return OperatingSystems::normalize($comment[$i] ?? null);
    }

    private function windowsMediaPlayerOs(): string
    {
        $version = $this->baseVersion() ?? throw RubyError::noMethod('to_a');
        $major = $version->segment(0);
        $at = static fn (int $index): int|string|null => $version->segment($index);

        // `version.to_a[0] <= 4`
        if (null === $major) {
            throw RubyError::noMethod('<=');
        }
        if (\is_string($major)) {
            throw RubyError::argument('comparison of String with 4 failed');
        }

        if ($major <= 4) {
            return match ($at(3)) {
                3564, 3925 => 'Windows 98',
                3857 => 'Windows 9x',
                3936 => 'Windows XP',
                3938 => 'Windows 2000',
                default => 'Windows',
            };
        }

        return match ($major) {
            7 => 3055 === $at(3) ? 'Windows 98' : 'Windows',
            8 => 'Windows XP',
            9, 10 => match ($at(3)) {
                2980 => 'Windows 98/2000',
                3268, 3367, 3270 => 'Windows 2000',
                3802, 4503 => 'Windows XP',
                default => 'Windows',
            },
            11, 12 => match ($at(2)) {
                9841, 9858, 9860, 9879 => 'Windows 10',
                9651 => 'Windows Phone 8.1',
                9600 => 'Windows 8.1',
                9200 => 'Windows 8',
                7600, 7601 => 'Windows 7',
                6000, 6001, 6002 => 'Windows Vista',
                5721 => 'Windows XP',
                default => 'Windows',
            },
            default => 'Windows',
        };
    }

    /** `string =~ regexp` with nil allowed on the left (nil never matches). */
    private static function matches(string $regexp, ?string $string): bool
    {
        return null !== $string && 1 === preg_match($regexp, $string);
    }

    /**
     * A case-insensitive Ruby regexp: Unicode case folding on UTF-8 strings (Ruby's /i folds
     * U+212A KELVIN SIGN to "k"), byte matching otherwise.
     *
     * @return array<int|string, string>|null
     */
    private static function matchI(string $regexp, string $string): ?array
    {
        $result = mb_check_encoding($string, 'UTF-8') ? preg_match($regexp.'u', $string, $m) : preg_match($regexp, $string, $m);

        return 1 === $result ? $m : null;
    }

    private static function matchesI(string $regexp, ?string $string): bool
    {
        return null !== $string && null !== self::matchI($regexp, $string);
    }

    /** Ruby's String#strip (ASCII whitespace and NUL). */
    private static function strip(string $string): string
    {
        return rtrim(ltrim($string, " \t\n\v\f\r"), " \t\n\v\f\r\0");
    }
}
