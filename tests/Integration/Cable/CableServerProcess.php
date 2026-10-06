<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cable;

use App\Tests\Support\SeedDatabase;
use Symfony\Component\Process\Process;

/**
 * `bin/console campfire:cable` in a subprocess, on a free port, against its own copy of the seed.
 */
final class CableServerProcess
{
    public readonly string $storagePath;

    public readonly string $socketPath;

    public readonly int $port;

    private Process $process;

    /** @param array<string, string> $env */
    public function __construct(array $env = [])
    {
        $this->storagePath = SeedDatabase::copy();
        $this->socketPath = $this->storagePath.'/cable.sock';
        $this->port = self::freePort();
        $this->process = new Process(
            ['php', 'bin/console', 'campfire:cable', '--port', (string) $this->port, '--socket', $this->socketPath],
            SeedDatabase::projectDir(),
            ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'CAMPFIRE_STORAGE_PATH' => $this->storagePath, 'CAMPFIRE_CABLE_SOCKET' => $this->socketPath] + $env,
            null,
            null,
        );
        $this->process->start();
        $this->waitUntilListening();
    }

    public function url(): string
    {
        return 'ws://127.0.0.1:'.$this->port.'/cable';
    }

    public function origin(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    public function output(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->signal(\SIGTERM);
            $this->process->wait();
        }
        SeedDatabase::remove($this->storagePath);
    }

    public function signal(int $signal): void
    {
        $this->process->signal($signal);
    }

    public function wait(): int
    {
        return (int) $this->process->wait();
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                throw new \RuntimeException("campfire:cable exited:\n".$this->output());
            }
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 0.1);
            if (false !== $socket && file_exists($this->socketPath)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("campfire:cable did not start:\n".$this->output());
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $server) {
            throw new \RuntimeException('No free port');
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
