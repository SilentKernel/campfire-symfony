<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Http\Platform\ApplicationPlatform;
use App\Http\Platform\BrowserBlocker;
use App\Http\Platform\RubyError;
use App\Http\Platform\UserAgent;
use App\Http\Platform\Version;
use App\Tests\Unit\Rails\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tests/vectors/campfire_user_agents.json: the useragent gem, Rails' allow_browser with
 * Campfire's VERSIONS and ApplicationPlatform, as the reference app answered them. A
 * `{"error": "NoMethodError"}` value means Ruby raised.
 */
final class UserAgentVectorsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function userAgents(): iterable
    {
        foreach (Vectors::get('user_agents', 'campfire_user_agents') as $i => $case) {
            yield \sprintf('#%d %s', $i, substr((string) $case['ua'], 0, 80)) => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('userAgents')]
    public function testUserAgent(array $case): void
    {
        $ua = $case['ua'];
        $agent = UserAgent::parse($ua);

        self::assertSame($case['browser'], self::answer(static fn () => $agent->browser()), 'browser');
        self::assertSame($case['version'], self::answer(static function () use ($agent): ?string {
            $version = $agent->version();

            return null === $version ? null : (string) $version;
        }), 'version');
        self::assertSame($case['platform'], self::answer(static fn () => $agent->platform()), 'platform');
        self::assertSame($case['os'], self::answer(static fn () => $agent->os()), 'os');
        self::assertSame($case['bot'], self::answer(static fn () => $agent->isBot()), 'bot');
        self::assertSame($case['mobile'], self::answer(static fn () => $agent->isMobile()), 'mobile');
        self::assertSame($case['blocked'], self::answer(static fn () => BrowserBlocker::blocked($ua)), 'blocked');

        $platform = new ApplicationPlatform($ua);
        $methods = [
            'ios' => 'ios', 'android' => 'android', 'mac' => 'mac', 'chrome' => 'chrome', 'firefox' => 'firefox',
            'safari' => 'safari', 'edge' => 'edge', 'apple_messages' => 'appleMessages', 'mobile' => 'mobile',
            'desktop' => 'desktop', 'windows' => 'windows', 'operating_system' => 'operatingSystem', 'browser' => 'browser',
        ];
        foreach ($case['application_platform'] as $key => $expected) {
            self::assertArrayHasKey($key, $methods);
            self::assertSame($expected, self::answer(static fn () => $platform->{$methods[$key]}()), 'application_platform.'.$key);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function versions(): iterable
    {
        foreach (Vectors::get('versions', 'campfire_user_agents') as $i => $case) {
            yield \sprintf('#%d "%s"', $i, $case['string']) => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('versions')]
    public function testVersion(array $case): void
    {
        $version = new Version($case['string']);

        self::assertSame($case['nil'], $version->isNil());
        self::assertSame($case['to_a'], array_map(static fn (array $s): string => $s[0].':'.$s[1], $version->segments()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function comparisons(): iterable
    {
        foreach (Vectors::get('comparisons', 'campfire_user_agents') as $i => $case) {
            yield \sprintf('#%d "%s" <=> "%s"', $i, $case['a'], $case['b']) => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('comparisons')]
    public function testComparison(array $case): void
    {
        $a = new Version($case['a']);
        $b = new Version($case['b']);

        self::assertSame($case['cmp'], $a->compare($b));
        self::assertSame($case['lt'], $a->lessThan($b));
        self::assertSame($case['eq'], $a->equals($b));
    }

    private static function answer(callable $call): mixed
    {
        try {
            return $call();
        } catch (RubyError $error) {
            return ['error' => $error->rubyClass];
        }
    }
}
