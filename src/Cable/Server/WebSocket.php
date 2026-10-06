<?php

declare(strict_types=1);

namespace App\Cable\Server;

/**
 * RFC 6455 framing for the server side: unmasked frames out, masked client frames in.
 *
 * Frames are built once per message and written as-is to every socket that gets it.
 */
final class WebSocket
{
    public const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public const int OP_CONTINUATION = 0x0;
    public const int OP_TEXT = 0x1;
    public const int OP_BINARY = 0x2;
    public const int OP_CLOSE = 0x8;
    public const int OP_PING = 0x9;
    public const int OP_PONG = 0xA;

    public const int CLOSE_NORMAL = 1000;
    public const int CLOSE_PROTOCOL_ERROR = 1002;
    public const int CLOSE_TOO_LARGE = 1009;

    public static function text(string $payload): string
    {
        return self::frame(self::OP_TEXT, $payload);
    }

    public static function close(int $code = self::CLOSE_NORMAL): string
    {
        return self::frame(self::OP_CLOSE, pack('n', $code));
    }

    public static function pong(string $payload): string
    {
        return self::frame(self::OP_PONG, $payload);
    }

    public static function frame(int $opcode, string $payload): string
    {
        $length = \strlen($payload);
        $head = \chr(0x80 | $opcode);

        return match (true) {
            $length < 126 => $head.\chr($length).$payload,
            $length <= 0xFFFF => $head.\chr(126).pack('n', $length).$payload,
            default => $head.\chr(127).pack('J', $length).$payload,
        };
    }

    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key.self::GUID, true));
    }

    /**
     * Parses one client frame at the start of $buffer.
     *
     * @return array{fin: bool, opcode: int, payload: string, length: int}|int|null the frame and
     *                                                                              its byte length, null when more bytes are needed, or a close code for a protocol error
     */
    public static function parse(string $buffer, int $maxPayload): array|int|null
    {
        $available = \strlen($buffer);
        if ($available < 2) {
            return null;
        }
        $first = \ord($buffer[0]);
        $second = \ord($buffer[1]);
        if (0 !== ($first & 0x70)) {
            return self::CLOSE_PROTOCOL_ERROR; // RSV bits: no extension was negotiated
        }
        if (0 === ($second & 0x80)) {
            return self::CLOSE_PROTOCOL_ERROR; // clients must mask
        }
        $opcode = $first & 0x0F;
        $length = $second & 0x7F;
        $offset = 2;
        if (126 === $length) {
            if ($available < 4) {
                return null;
            }
            $length = (int) (unpack('n', $buffer, 2) ?: [1 => 0])[1];
            $offset = 4;
        } elseif (127 === $length) {
            if ($available < 10) {
                return null;
            }
            $length = (int) (unpack('J', $buffer, 2) ?: [1 => 0])[1];
            $offset = 10;
        }
        if ($length > $maxPayload || $length < 0) {
            return self::CLOSE_TOO_LARGE;
        }
        if ($opcode >= 0x8 && ($length > 125 || 0 === ($first & 0x80))) {
            return self::CLOSE_PROTOCOL_ERROR;
        }
        if ($available < $offset + 4 + $length) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $length);
        if ($length > 0) {
            $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
            $payload = substr($payload, 0, $length);
        }

        return ['fin' => 0 !== ($first & 0x80), 'opcode' => $opcode, 'payload' => $payload, 'length' => $offset + 4 + $length];
    }
}
