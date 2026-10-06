<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Messages;

use App\Opengraph\Fetch;
use App\Opengraph\HostResolver;
use App\Opengraph\PrivateNetworkGuard;
use App\Opengraph\Unfurler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Link unfurling (reference/app/models/opengraph, UnfurlLinksController#create) replayed over the
 * corpus the Rust port recorded from the reference (fixtures/opengraph_cases.json, MIT): fake DNS,
 * a fake server behind the fake public addresses, and the reference's response and DNS lookups
 * for each case (fixtures/opengraph_expected.json).
 */
final class UnfurlerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function cases(): iterable
    {
        $spec = self::spec();
        $expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/opengraph_expected.json'), true);
        foreach ($spec['cases'] as $i => $case) {
            yield $case['name'] => [$case, $expected[$i]];
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    #[DataProvider('cases')]
    public function testUnfurlsLikeTheReference(array $case, array $expected): void
    {
        $resolver = new FakeResolver(self::spec()['hosts']);
        $requests = [];
        $unfurler = new Unfurler($guard = new PrivateNetworkGuard($resolver), new Fetch($this->server($requests), $guard));

        try {
            $json = $unfurler->unfurl($case['url']);
            $response = null === $json ? ['status' => 204] : ['status' => 200, 'body' => $json];
        } catch (\RuntimeException) {
            $response = ['status' => 500];
        }

        $wanted = $expected['response'];
        unset($wanted['error']);
        self::assertSame($wanted, $response);
        self::assertSame($expected['lookups'], $resolver->lookups, 'DNS lookups');
        self::assertSame(
            array_map(static fn (array $r): array => [$r[0], $r[1], $r[2]], $expected['requests']),
            $requests,
            'HTTP requests',
        );
    }

    public function testTwitterUrlsAreReadThroughFxtwitter(): void
    {
        self::assertTrue(Unfurler::isTweetUrl('https://x.com/dhh/status/1'));
        self::assertFalse(Unfurler::isTweetUrl('https://x.com/'));
        self::assertFalse(Unfurler::isTweetUrl('https://example.com/dhh/status/1'));
        self::assertSame('https://fxtwitter.com/dhh/status/1?s=20', Unfurler::replaceTwitterDomain('https://www.twitter.com/dhh/status/1?s=20'));
    }

    public function testThePrivateNetworkGuard(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1', '64:ff9b::a00:1'] as $ip) {
            self::assertTrue(PrivateNetworkGuard::isBlocked($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946', '64:ff9b::808:808'] as $ip) {
            self::assertFalse(PrivateNetworkGuard::isBlocked($ip), $ip);
        }

        $guard = new PrivateNetworkGuard(new FakeResolver(['mixed.example' => [['10.0.0.9', '::1', '93.184.216.39']]]));
        self::assertSame('93.184.216.39', $guard->resolve('mixed.example'));
        self::assertNull($guard->resolve('0x7f.1'));
        self::assertNull($guard->resolve('2130706433'));
        self::assertNull($guard->resolve('under_score.example'));
        self::assertNull($guard->resolve('nowhere.example'));
    }

    /**
     * The fake server: routes by method, host and request target; only the fake public
     * addresses connect.
     *
     * @param list<list<string>> $requests
     */
    private function server(array &$requests): MockHttpClient
    {
        $spec = self::spec();
        $public = $spec['public_ips'];

        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $spec, $public): MockResponse {
            $parts = parse_url($url);
            $host = (string) ($parts['host'] ?? '');
            $ip = $options['resolve'][$host] ?? null;
            if (!\in_array($ip, $public, true)) {
                throw new TransportException(\sprintf('Could not connect to %s (%s)', $host, $ip));
            }
            $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $requests[] = [$method, $host.(isset($parts['port']) ? ':'.$parts['port'] : ''), $target];

            foreach ($spec['routes'] as $route) {
                if ($route['method'] === $method && ('*' === $route['host'] || $route['host'] === $host) && $route['path'] === $target) {
                    return self::response($route);
                }
            }

            return new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['Content-Type: text/plain']]);
        });
    }

    /** @param array<string, mixed> $route */
    private static function response(array $route): MockResponse
    {
        if (isset($route['body_b64'])) {
            $body = (string) base64_decode($route['body_b64'], true);
        } elseif (isset($route['body_repeat'])) {
            $body = str_repeat($route['body_repeat'][0], $route['body_repeat'][1]);
        } else {
            $body = (string) ($route['body'] ?? '');
        }
        if (isset($route['pad_to'])) {
            $body = str_pad($body, $route['pad_to']);
        }
        $headers = array_map(static fn (array $h): string => $h[0].': '.$h[1], $route['headers']);
        // Net::HTTP (and curl) inflate gzipped bodies; the fake server stands for that.
        $chunks = !empty($route['chunked']) ? str_split($body, 65536) : $body;

        return new MockResponse($chunks, ['http_code' => $route['status'], 'response_headers' => $headers]);
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(__DIR__.'/fixtures/opengraph_cases.json'), true);
    }
}

/** Resolv.getaddresses over a fixed table; a host with several answer lists gives the next each time. */
final class FakeResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param array<string, list<list<string>>> $hosts */
    public function __construct(private array $hosts)
    {
    }

    public function resolve(string $hostname): array
    {
        $this->lookups[] = $hostname;
        $answers = $this->hosts[$hostname] ?? null;
        if (null === $answers) {
            return [];
        }

        return \count($answers) > 1 ? array_shift($this->hosts[$hostname]) : $answers[0];
    }
}
