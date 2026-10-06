<?php

declare(strict_types=1);

namespace App\Opengraph;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * RestrictedHTTP::PrivateNetworkGuard (reference/lib/restricted_http/private_network_guard.rb)
 * over the surfguard gem's default policy: resolve() is `Surfguard.resolve_public_ips(host).first`,
 * the address the fetch is then pinned to, or null where Rails raises (blocked, malformed or
 * unresolvable host).
 */
final readonly class PrivateNetworkGuard
{
    /** Surfguard::DISALLOWED_IPV4, plus IPAddr#private?/loopback?/link_local?. */
    public const array DISALLOWED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '168.63.129.16/32', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** Surfguard::DISALLOWED_IPV6, plus IPAddr#private?/loopback?/link_local? and the IETF assignments. */
    public const array DISALLOWED_IPV6 = [
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', '2001::/23', '100::/64', '100:0:0:1::/64', '2001::/32',
        '2001:2::/48', '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16', 'fec0::/10', 'ff00::/8',
    ];

    public const array GLOBALLY_REACHABLE_IETF_ASSIGNMENTS = ['2001:3::/32', '2001:4:112::/48'];

    public const array IANA_ALLOCATED_IPV6_UNICAST = [
        '2001::/23', '2001:200::/23', '2001:400::/23', '2001:600::/23', '2001:800::/22', '2001:c00::/23',
        '2001:e00::/23', '2001:1200::/23', '2001:1400::/22', '2001:1800::/23', '2001:1a00::/23', '2001:1c00::/22',
        '2001:2000::/19', '2001:4000::/23', '2001:4200::/23', '2001:4400::/23', '2001:4600::/23', '2001:4800::/23',
        '2001:4a00::/23', '2001:4c00::/23', '2001:5000::/20', '2001:8000::/19', '2001:a000::/20', '2001:b000::/20',
        '2002::/16', '2003::/18', '2400::/12', '2410::/12', '2600::/12', '2610::/23', '2620::/23', '2630::/12',
        '2800::/12', '2a00::/12', '2a10::/12', '2c00::/12',
    ];

    /**
     * Every subnet NoPrivateNetworkHttpClient refuses: Symfony's private subnets plus what surfguard
     * blocks on top of them (multicast, the Azure wire server, 6to4 relays…).
     */
    public const array BLOCKED_SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,
        '168.63.129.16/32', '192.0.0.0/24', '192.88.99.0/24', '224.0.0.0/4', '100::/64', '100:0:0:1::/64',
        '3fff::/20', '5f00::/16', 'fec0::/10', 'ff00::/8',
    ];

    private const int MAX_HOST_BYTES = 255;

    public function __construct(private HostResolver $resolver)
    {
    }

    /** `PrivateNetworkGuard.resolve(hostname)`: the first public address (IPv4 first), or null. */
    public function resolve(?string $hostname): ?string
    {
        if (null === $hostname || '' === $hostname || \strlen($hostname) > self::MAX_HOST_BYTES
            || str_contains($hostname, '%') || str_contains($hostname, "\0") || 1 !== preg_match('/\A[\x00-\x7F]*\z/', $hostname)) {
            return null;
        }

        $addresses = $this->addresses($hostname);
        if (null === $addresses) {
            return null;
        }

        $v4 = $v6 = [];
        foreach ($addresses as $ip) {
            if (!self::isBlocked($ip)) {
                str_contains($ip, ':') ? $v6[] = $ip : $v4[] = $ip;
            }
        }

        return $v4[0] ?? $v6[0] ?? null;
    }

    /** `Surfguard.blocked_address?(ip)` */
    public static function isBlocked(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return IpUtils::checkIp($ip, self::DISALLOWED_IPV4);
        }
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return true;
        }

        $binary = (string) inet_pton($ip);
        // IPv4-mapped (::ffff:0:0/96) and IPv4-compatible (::/96) addresses.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") || str_starts_with($binary, str_repeat("\0", 12))) {
            return true;
        }
        if (IpUtils::checkIp($ip, '64:ff9b:1::/48')) {
            return true;
        }
        // NAT64 (64:ff9b::/96) and IPv4-translatable (::ffff:0:0:0/96): the embedded IPv4 decides.
        if (IpUtils::checkIp($ip, ['64:ff9b::/96', '::ffff:0:0:0/96'])) {
            return self::isBlocked((string) inet_ntop(substr($binary, 12)));
        }
        if (IpUtils::checkIp($ip, self::GLOBALLY_REACHABLE_IETF_ASSIGNMENTS)) {
            return false;
        }
        if (IpUtils::checkIp($ip, self::DISALLOWED_IPV6)) {
            return true;
        }

        return !IpUtils::checkIp($ip, self::IANA_ALLOCATED_IPV6_UNICAST);
    }

    /**
     * Numeric literals (with getaddrinfo's legacy IPv4 forms: "127.1", "0x7f.0.0.1") resolve to
     * themselves; anything else must be a valid hostname and goes to DNS.
     *
     * @return list<string>|null null when the host is malformed
     */
    private function addresses(string $host): ?array
    {
        $literal = trim($host, '[]');
        if (str_contains($host, ':')) {
            return false !== filter_var($literal, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? [$literal] : null;
        }
        if (self::isLegacyIpv4Shape($host)) {
            $ip = self::legacyIpv4($host);

            return null !== $ip ? [$ip] : null;
        }
        $labels = explode('.', str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
        foreach ($labels as $label) {
            if ('' === $label || \strlen($label) > 63 || 1 !== preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/', $label)) {
                return null;
            }
        }

        $addresses = $this->resolver->resolve($host);
        foreach ($addresses as $ip) {
            if (false === filter_var(trim($ip, '[]'), \FILTER_VALIDATE_IP)) {
                return null;
            }
        }

        return [] === $addresses ? null : array_slice($addresses, 0, 256);
    }

    private static function isLegacyIpv4Shape(string $text): bool
    {
        $parts = array_values(array_filter(explode('.', $text), static fn (string $part): bool => '' !== $part));

        return \count($parts) >= 1 && \count($parts) <= 4
            && [] === array_filter($parts, static fn (string $part): bool => 1 !== preg_match('/\A(?:0[xX][0-9A-Fa-f]+|[0-9]+)\z/', $part));
    }

    /** inet_aton: 1 to 4 parts, each decimal, octal (leading 0) or hex (0x); the last fills the rest. */
    private static function legacyIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        if ('' === end($parts)) {
            array_pop($parts);
        }
        if ([] === $parts || \count($parts) > 4) {
            return null;
        }
        $values = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                return null;
            }
            if (1 === preg_match('/\A0[xX]([0-9A-Fa-f]*)\z/', $part, $m)) {
                $value = '' === $m[1] ? 0 : hexdec($m[1]);
            } elseif (1 === preg_match('/\A0[0-7]*\z/', $part)) {
                $value = octdec($part);
            } elseif (1 === preg_match('/\A[1-9][0-9]*\z/', $part)) {
                $value = (int) $part;
            } else {
                return null;
            }
            $values[] = (int) $value;
        }
        $last = array_pop($values);
        foreach ($values as $value) {
            if ($value > 255) {
                return null;
            }
        }
        $remaining = 4 - \count($values);
        if ($last >= 2 ** (8 * $remaining)) {
            return null;
        }
        $address = 0;
        foreach ($values as $i => $value) {
            $address |= $value << (8 * (3 - $i));
        }
        $address |= $last;

        return long2ip($address) ?: null;
    }
}
