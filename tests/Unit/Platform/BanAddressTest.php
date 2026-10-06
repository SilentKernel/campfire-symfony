<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Domain\Users\Bans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ban#ip_address_is_public (reference/app/models/ban.rb) with Ruby IPAddr's predicates. */
final class BanAddressTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function addresses(): iterable
    {
        $private = 'cannot be a private or internal IP address';
        $invalid = 'is not a valid IP address';

        yield 'public v4' => ['203.0.113.9', null];
        yield 'public v6' => ['2001:4860:4860::8888', null];
        yield 'unspecified' => ['0.0.0.0', null];
        yield 'loopback' => ['127.0.0.1', $private];
        yield 'loopback range' => ['127.255.0.1', $private];
        yield 'loopback v6' => ['::1', $private];
        yield 'mapped loopback' => ['::ffff:127.0.0.1', $private];
        yield 'compatible loopback is public' => ['::127.0.0.1', null];
        yield '10/8' => ['10.1.2.3', $private];
        yield '172.16/12' => ['172.31.0.1', $private];
        yield '172.32 is public' => ['172.32.0.1', null];
        yield '192.168/16' => ['192.168.1.1', $private];
        yield 'mapped private' => ['::ffff:10.0.0.1', $private];
        yield 'unique local' => ['fd00::1', $private];
        yield 'link local' => ['169.254.1.1', $private];
        yield 'link local v6' => ['fe80::1', $private];
        yield 'link local v6 with zone' => ['fe80::1%eth0', $private];
        yield 'prefix' => ['1.2.3.4/8', null];
        yield 'bad prefix' => ['10.0.0.1/33', $invalid];
        yield 'short' => ['1.2.3', $invalid];
        yield 'garbage' => ['nope', $invalid];
        yield 'leading space' => [' 1.2.3.4', $invalid];
    }

    #[DataProvider('addresses')]
    public function testValidation(string $ip, ?string $error): void
    {
        self::assertSame($error, Bans::ipAddressError($ip));
    }
}
