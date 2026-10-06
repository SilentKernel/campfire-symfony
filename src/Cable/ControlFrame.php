<?php

declare(strict_types=1);

namespace App\Cable;

/**
 * The publish protocol between the app (SocketBroadcaster) and campfire:cable, over the unix
 * socket at %campfire.cable_socket%. Each frame is length-prefixed, the way Workerman's Frame
 * protocol reads them: a 4-byte big-endian total length (header included), then the body.
 *
 * - broadcast:  "B" . u16 stream length . stream . payload (already Active Support JSON)
 * - disconnect: "D" . u32 user id . "0" | "1" (reconnect)
 *
 * Several frames may travel in one write; the server reads them in order.
 */
final class ControlFrame
{
    public const string BROADCAST = 'B';
    public const string DISCONNECT = 'D';

    public static function broadcast(string $stream, string $encodedPayload): string
    {
        if (\strlen($stream) > 0xFFFF) {
            throw new \InvalidArgumentException('Stream name too long');
        }

        return self::frame(self::BROADCAST.pack('n', \strlen($stream)).$stream.$encodedPayload);
    }

    public static function disconnect(int $userId, bool $reconnect): string
    {
        return self::frame(self::DISCONNECT.pack('N', $userId).($reconnect ? '1' : '0'));
    }

    /**
     * Decodes one frame body (without its length prefix).
     *
     * @return array{type: 'B', stream: string, payload: string}|array{type: 'D', userId: int, reconnect: bool}|null
     */
    public static function decode(string $body): ?array
    {
        $type = $body[0] ?? '';
        if (self::BROADCAST === $type && \strlen($body) >= 3) {
            $length = unpack('n', $body, 1)[1] ?? 0;
            if (\strlen($body) < 3 + $length) {
                return null;
            }

            return ['type' => self::BROADCAST, 'stream' => substr($body, 3, $length), 'payload' => substr($body, 3 + $length)];
        }
        if (self::DISCONNECT === $type && 6 === \strlen($body)) {
            return ['type' => self::DISCONNECT, 'userId' => (int) (unpack('N', $body, 1)[1] ?? 0), 'reconnect' => '1' === $body[5]];
        }

        return null;
    }

    private static function frame(string $body): string
    {
        return pack('N', 4 + \strlen($body)).$body;
    }
}
