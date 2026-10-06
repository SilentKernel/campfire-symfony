<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cable;

use App\Cable\ControlFrame;
use PHPUnit\Framework\TestCase;

final class ControlFrameTest extends TestCase
{
    public function testBroadcastRoundTrip(): void
    {
        $frame = ControlFrame::broadcast('Z2lk:messages', '"<p>é</p>"');

        self::assertSame(\strlen($frame), unpack('N', $frame)[1], 'the length counts the header too (Workerman Frame)');
        self::assertSame(['type' => 'B', 'stream' => 'Z2lk:messages', 'payload' => '"<p>é</p>"'], ControlFrame::decode(substr($frame, 4)));
    }

    public function testDisconnectRoundTrip(): void
    {
        self::assertSame(['type' => 'D', 'userId' => 127326141, 'reconnect' => true], ControlFrame::decode(substr(ControlFrame::disconnect(127326141, true), 4)));
        self::assertSame(['type' => 'D', 'userId' => 1, 'reconnect' => false], ControlFrame::decode(substr(ControlFrame::disconnect(1, false), 4)));
    }

    public function testRejectsGarbage(): void
    {
        self::assertNull(ControlFrame::decode(''));
        self::assertNull(ControlFrame::decode('X123'));
        self::assertNull(ControlFrame::decode("B\x00\x09abc"));
        self::assertNull(ControlFrame::decode('D12'));
    }
}
