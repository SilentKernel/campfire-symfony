<?php

declare(strict_types=1);

namespace App\Opengraph;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Resolv.getaddresses, through the system resolver. */
#[AsAlias(HostResolver::class)]
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $hostname): array
    {
        $addresses = [];
        foreach (@gethostbynamel($hostname) ?: [] as $ip) {
            $addresses[] = $ip;
        }
        $records = @dns_get_record($hostname, \DNS_AAAA);
        foreach (\is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
