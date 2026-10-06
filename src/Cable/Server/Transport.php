<?php

declare(strict_types=1);

namespace App\Cable\Server;

/** The socket under a Connection (a Workerman TcpConnection in the server, a fake in tests). */
interface Transport
{
    /** Writes bytes as they are (already framed). */
    public function send(string $bytes): void;

    /** Writes $bytes, then closes once they are sent. */
    public function close(string $bytes = ''): void;
}
