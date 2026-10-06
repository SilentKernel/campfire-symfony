<?php

declare(strict_types=1);

namespace App\Http\Platform;

/** `UserAgent::OperatingSystems` (useragent gem, lib/user_agent/operating_systems.rb). */
final class OperatingSystems
{
    public const string IOS_VERSION_REGEX = '/CPU (?:iPhone |iPod )?OS ([\d_]+) like Mac OS X/';

    private const array WINDOWS = [
        'Windows NT 10.0' => 'Windows 10',
        'Windows NT 6.3' => 'Windows 8.1',
        'Windows NT 6.2' => 'Windows 8',
        'Windows NT 6.1' => 'Windows 7',
        'Windows NT 6.0' => 'Windows Vista',
        'Windows NT 5.2' => 'Windows XP x64 Edition',
        'Windows NT 5.1' => 'Windows XP',
        'Windows NT 5.01' => 'Windows 2000, Service Pack 1 (SP1)',
        'Windows NT 5.0' => 'Windows 2000',
        'Windows NT 4.0' => 'Windows NT 4.0',
        'Windows 98' => 'Windows 98',
        'Windows 95' => 'Windows 95',
        'Windows CE' => 'Windows CE',
    ];

    public static function normalize(?string $os): ?string
    {
        if (null === $os) {
            return null;
        }
        if (isset(self::WINDOWS[$os])) {
            return self::WINDOWS[$os];
        }
        if (preg_match('/(?:Intel|PPC) Mac OS X[ \t\r\n\f\v]*([0-9_\.]+)?/', $os, $m)) {
            return isset($m[1]) ? 'OS X '.strtr($m[1], '_', '.') : 'OS X';
        }
        if (preg_match(self::IOS_VERSION_REGEX, $os, $m)) {
            return 'iOS '.strtr($m[1], '_', '.');
        }
        if (preg_match('/CrOS[ \t\r\n\f\v]([^ \t\r\n\f\v]+)[ \t\r\n\f\v](\d+(\.\d+)*)/', $os, $m)) {
            return 'ChromeOS '.$m[2];
        }

        return $os;
    }
}
