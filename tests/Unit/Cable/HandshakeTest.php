<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cable;

use App\Cable\Server\Handshake;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    public function testParsesAWebSocketUpgrade(): void
    {
        $handshake = self::upgrade(['Origin' => 'https://campfire.example', 'Cookie' => 'a=1', 'cookie' => 'b=2']);

        self::assertTrue($handshake->isWebSocket());
        self::assertSame('a=1; b=2', $handshake->header('cookie'));
        self::assertSame('actioncable-v1-json', $handshake->protocol());
        self::assertStringContainsString("Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n", (string) $handshake->accept());
        self::assertStringEndsWith("Sec-WebSocket-Protocol: actioncable-v1-json\r\n\r\n", (string) $handshake->accept());
    }

    public function testProtocolFollowsTheClientsOrder(): void
    {
        self::assertSame('actioncable-unsupported', self::upgrade(['Sec-WebSocket-Protocol' => 'actioncable-unsupported, actioncable-v1-json'])->protocol());
        self::assertNull(self::upgrade(['Sec-WebSocket-Protocol' => 'foo'])->protocol());
        self::assertStringNotContainsString('Sec-WebSocket-Protocol', (string) self::upgrade(['Sec-WebSocket-Protocol' => 'foo'])->accept());
    }

    public function testNotAWebSocket(): void
    {
        self::assertFalse(Handshake::parse("GET /cable HTTP/1.1\r\nHost: x\r\n\r\n")?->isWebSocket());
        self::assertFalse(Handshake::parse("POST /cable HTTP/1.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n")?->isWebSocket());
        self::assertTrue(Handshake::parse("GET /cable HTTP/1.1\r\nUpgrade: WebSocket\r\nConnection: keep-alive, Upgrade\r\n\r\n")?->isWebSocket());
        self::assertNull(Handshake::parse("garbage\r\n\r\n"));
        self::assertNull(self::upgrade(['Sec-WebSocket-Version' => '8'])->accept());
    }

    public function testSameOriginRule(): void
    {
        $https = self::upgrade(['Origin' => 'https://campfire.example']);
        self::assertTrue($https->allowsOrigin(assumeSsl: true));
        self::assertFalse($https->allowsOrigin(assumeSsl: false), 'plain http compares with http://');

        $http = self::upgrade(['Origin' => 'http://campfire.example']);
        self::assertTrue($http->allowsOrigin(assumeSsl: false));
        self::assertFalse($http->allowsOrigin(assumeSsl: true));

        self::assertTrue(self::upgrade(['Origin' => 'https://campfire.example', 'X-Forwarded-Proto' => 'https'])->allowsOrigin(assumeSsl: false));
        self::assertFalse(self::upgrade(['Origin' => 'https://evil.example'])->allowsOrigin(assumeSsl: true));
        self::assertFalse(self::upgrade([])->allowsOrigin(assumeSsl: true), 'no origin');
        self::assertTrue(self::upgrade(['Origin' => 'http://localhost:3000'])->allowsOrigin(false, ['~\Ahttps?://localhost:\d+\z~']));
        self::assertTrue(self::upgrade(['Origin' => 'https://evil.example'])->allowsOrigin(true, [], disableForgeryProtection: true));
    }

    /** @param array<string, string> $headers */
    private static function upgrade(array $headers): Handshake
    {
        $headers += [
            'Host' => 'campfire.example',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'actioncable-v1-json, actioncable-unsupported',
        ];
        $head = "GET /cable HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $head .= "$name: $value\r\n";
        }

        return Handshake::parse($head."\r\n") ?? throw new \LogicException('unparsable');
    }
}
