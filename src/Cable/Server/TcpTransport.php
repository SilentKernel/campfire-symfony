<?php

declare(strict_types=1);

namespace App\Cable\Server;

use Workerman\Connection\TcpConnection;

final readonly class TcpTransport implements Transport
{
    public function __construct(private TcpConnection $connection)
    {
    }

    public function send(string $bytes): void
    {
        $this->connection->send($bytes, true);
    }

    public function close(string $bytes = ''): void
    {
        if ('' === $bytes) {
            $this->connection->close();
        } else {
            $this->connection->close($bytes, true);
        }
    }
}
