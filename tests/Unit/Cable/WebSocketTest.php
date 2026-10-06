<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cable;

use App\Cable\Server\WebSocket;
use PHPUnit\Framework\TestCase;

final class WebSocketTest extends TestCase
{
    public function testServerFramesAreUnmaskedWithTheRightLength(): void
    {
        self::assertSame("\x81\x02hi", WebSocket::text('hi'));
        self::assertSame("\x81\x7e\x01\x00".str_repeat('a', 256), WebSocket::text(str_repeat('a', 256)));
        self::assertSame("\x81\x7f".pack('J', 70000).str_repeat('a', 70000), WebSocket::text(str_repeat('a', 70000)));
        self::assertSame("\x88\x02\x03\xe8", WebSocket::close());
    }

    public function testParsesMaskedClientFrames(): void
    {
        $frame = self::clientFrame(0x1, 'hello');
        self::assertSame(['fin' => true, 'opcode' => 1, 'payload' => 'hello', 'length' => 11], WebSocket::parse($frame.'rest', 1024));
        self::assertNull(WebSocket::parse(substr($frame, 0, 7), 1024), 'needs more bytes');

        $long = self::clientFrame(0x1, str_repeat('x', 300));
        self::assertSame(str_repeat('x', 300), WebSocket::parse($long, 1024)['payload'] ?? null);
    }

    public function testProtocolErrors(): void
    {
        self::assertSame(WebSocket::CLOSE_PROTOCOL_ERROR, WebSocket::parse("\x81\x02hi", 1024), 'unmasked');
        self::assertSame(WebSocket::CLOSE_PROTOCOL_ERROR, WebSocket::parse("\xc1\x82abcdxx", 1024), 'RSV1 without an extension');
        self::assertSame(WebSocket::CLOSE_TOO_LARGE, WebSocket::parse(self::clientFrame(0x1, str_repeat('x', 2000)), 1024));
        self::assertSame(WebSocket::CLOSE_PROTOCOL_ERROR, WebSocket::parse(self::clientFrame(0x9, 'x', fin: false), 1024), 'fragmented control frame');
    }

    public function testAcceptKey(): void
    {
        // RFC 6455 section 1.3
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WebSocket::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    private static function clientFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $mask = "\x01\x02\x03\x04";
        $length = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0) | $opcode).($length < 126 ? \chr(0x80 | $length) : \chr(0x80 | 126).pack('n', $length));

        return $head.$mask.($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));
    }
}
