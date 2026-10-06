<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cable;

/**
 * A minimal blocking WebSocket client (RFC 6455) for the cable tests: it shows the HTTP answer to
 * the upgrade, every frame (text, close), and the end of the stream.
 */
final class CableClient
{
    public string $responseHead = '';

    /** @var resource */
    private $socket;

    private string $buffer = '';

    private bool $ended = false;

    /** @param array<string, string> $headers */
    public function __construct(int $port, array $headers = [], string $path = '/cable')
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 5);
        if (false === $socket) {
            throw new \RuntimeException("connect: $error");
        }
        $this->socket = $socket;
        $headers += [
            'Host' => '127.0.0.1:'.$port,
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'actioncable-v1-json, actioncable-unsupported',
            'Origin' => 'http://127.0.0.1:'.$port,
        ];
        $request = "GET $path HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            if ('' !== $value) {
                $request .= "$name: $value\r\n";
            }
        }
        fwrite($this->socket, $request."\r\n");
        $this->readHead();
    }

    public function status(): int
    {
        return (int) (explode(' ', $this->responseHead, 3)[1] ?? 0);
    }

    /** The response body of a refused upgrade. */
    public function body(): string
    {
        $this->fill(1.0, untilEnd: true);

        return $this->buffer;
    }

    /** @param array<string, mixed>|string $identifier */
    public function subscribe(array|string $identifier): string
    {
        $identifier = \is_string($identifier) ? $identifier : (string) json_encode($identifier);
        $this->sendJson(['command' => 'subscribe', 'identifier' => $identifier]);

        return $identifier;
    }

    public function unsubscribe(string $identifier): void
    {
        $this->sendJson(['command' => 'unsubscribe', 'identifier' => $identifier]);
    }

    /** @param array<string, mixed> $data */
    public function perform(string $identifier, array $data): void
    {
        $this->sendJson(['command' => 'message', 'identifier' => $identifier, 'data' => (string) json_encode($data)]);
    }

    /** @param array<string, mixed> $data */
    public function sendJson(array $data): void
    {
        $this->sendText((string) json_encode($data));
    }

    public function sendText(string $text, int $opcode = 0x1): void
    {
        $mask = random_bytes(4);
        $length = \strlen($text);
        $head = \chr(0x80 | $opcode);
        $head .= match (true) {
            $length < 126 => \chr(0x80 | $length),
            $length <= 0xFFFF => \chr(0x80 | 126).pack('n', $length),
            default => \chr(0x80 | 127).pack('J', $length),
        };
        $masked = $length > 0 ? substr($text ^ str_repeat($mask, intdiv($length, 4) + 1), 0, $length) : '';
        fwrite($this->socket, $head.$mask.$masked);
    }

    /**
     * The next frame, skipping Action Cable pings unless asked for: ['text', string] or
     * ['close', code], or null on timeout / end of stream.
     *
     * @return array{0: string, 1: string|int}|null
     */
    public function frame(float $timeout = 2.0, bool $pings = false): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $frame = $this->parse();
            if (null !== $frame) {
                if (!$pings && 'text' === $frame[0] && str_starts_with((string) $frame[1], '{"type":"ping"')) {
                    continue;
                }

                return $frame;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0 || $this->ended || !$this->fill($left)) {
                return null;
            }
        }
    }

    /** The next text message, decoded. @return array<string, mixed>|null */
    public function json(float $timeout = 2.0): ?array
    {
        $frame = $this->frame($timeout);
        if (null === $frame || 'text' !== $frame[0]) {
            return null;
        }

        return json_decode((string) $frame[1], true);
    }

    /** Raw text of the next message (null when none arrives). */
    public function text(float $timeout = 2.0): ?string
    {
        $frame = $this->frame($timeout);

        return null !== $frame && 'text' === $frame[0] ? (string) $frame[1] : null;
    }

    /** Everything that arrives within $timeout. @return list<string> */
    public function drain(float $timeout = 0.3): array
    {
        $texts = [];
        while (null !== ($frame = $this->frame($timeout))) {
            $texts[] = 'text' === $frame[0] ? (string) $frame[1] : 'close '.$frame[1];
        }

        return $texts;
    }

    /** True once the server has closed the TCP connection. */
    public function isEnded(float $timeout = 2.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (!$this->ended && microtime(true) < $deadline) {
            $this->fill($deadline - microtime(true));
            $this->buffer = '';
        }

        return $this->ended;
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function readHead(): void
    {
        $deadline = microtime(true) + 5;
        while (false === ($end = strpos($this->buffer, "\r\n\r\n"))) {
            if (!$this->fill($deadline - microtime(true))) {
                throw new \RuntimeException('No HTTP response: '.$this->buffer);
            }
        }
        $this->responseHead = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);
    }

    /** @return array{0: string, 1: string|int}|null */
    private function parse(): ?array
    {
        if (\strlen($this->buffer) < 2) {
            return null;
        }
        $opcode = \ord($this->buffer[0]) & 0x0F;
        $length = \ord($this->buffer[1]) & 0x7F;
        $offset = 2;
        if (126 === $length) {
            if (\strlen($this->buffer) < 4) {
                return null;
            }
            $length = unpack('n', $this->buffer, 2)[1];
            $offset = 4;
        } elseif (127 === $length) {
            if (\strlen($this->buffer) < 10) {
                return null;
            }
            $length = unpack('J', $this->buffer, 2)[1];
            $offset = 10;
        }
        if (\strlen($this->buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        return match ($opcode) {
            0x8 => ['close', \strlen($payload) >= 2 ? unpack('n', $payload)[1] : 0],
            0x1 => ['text', $payload],
            default => ['other', $payload],
        };
    }

    private function fill(float $timeout, bool $untilEnd = false): bool
    {
        $read = [$this->socket];
        $write = $except = null;
        $deadline = microtime(true) + max(0.0, $timeout);
        do {
            $left = max(0.0, $deadline - microtime(true));
            $read = [$this->socket];
            if (!stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000))) {
                return false;
            }
            $chunk = fread($this->socket, 65536);
            if (false === $chunk || '' === $chunk) {
                $this->ended = true;

                return false;
            }
            $this->buffer .= $chunk;
        } while ($untilEnd);

        return true;
    }
}
