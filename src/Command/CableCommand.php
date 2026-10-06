<?php

declare(strict_types=1);

namespace App\Command;

use App\Cable\Server\CableRepository;
use App\Cable\Server\CableServer;
use App\Cable\Server\ChannelRegistry;
use App\Cable\Server\Protocol;
use App\Cable\Server\TcpTransport;
use App\Http\Ssl;
use App\Rails\RailsCookies;
use App\Rails\TurboStreamName;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/**
 * The Action Cable server (what `mount ActionCable.server => "/cable"` serves in Rails), on
 * 127.0.0.1:$TARGET_PORT behind Caddy's /cable proxy, in one process and one event loop:
 *
 * - WebSocket connections speaking actioncable-v1-json (App\Cable\Server\Connection).
 * - The publish socket (%campfire.cable_socket%, a unix socket) where the app's
 *   SocketBroadcaster writes broadcasts and remote disconnects, Redis' role in Rails.
 * - The 3 second heartbeat.
 *
 * Workerman provides the loop (ext-event when loaded, else stream_select, which cannot watch file
 * descriptors past FD_SETSIZE = 1024, so about 1000 sockets) and buffered non-blocking sockets.
 * It runs without Workerman's master/worker supervisor: bin/start supervises this process. On
 * TERM/INT every client is told `server_restart` (they reconnect) and the process exits.
 */
#[AsCommand(name: 'campfire:cable', description: 'Run the Action Cable server')]
final readonly class CableCommand
{
    private const int MAX_SEND_BUFFER = 8 << 20;
    private const int MAX_CONTROL_FRAME = 64 << 20;
    private const int BUSY_TIMEOUT_MS = 20;

    public function __construct(
        private DbalConnection $connection,
        private ClockInterface $clock,
        private RailsCookies $cookies,
        private TurboStreamName $turboStreamName,
        private Ssl $ssl,
        private LoggerInterface $logger,
        #[Autowire('%campfire.cable_socket%')] private string $cableSocket,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Address to listen on')] string $host = '127.0.0.1',
        #[Option(description: 'Port to listen on (default: $TARGET_PORT, else 3001)')] ?int $port = null,
        #[Option(description: 'Publish socket path (default: %campfire.cable_socket%)')] ?string $socket = null,
    ): int {
        $port ??= (int) (getenv('TARGET_PORT') ?: 3001);
        $socket ??= $this->cableSocket;
        $runDir = $this->projectDir.'/var/run';
        foreach ([$runDir, \dirname($socket)] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                $io->error("Cannot create $dir");

                return Command::FAILURE;
            }
        }
        if (!$this->claimSocket($socket)) {
            $io->error("Another cable server is listening on $socket");

            return Command::FAILURE;
        }

        $repository = new CableRepository($this->connection, $this->clock);
        $repository->setBusyTimeout(self::BUSY_TIMEOUT_MS);
        $this->disconnectAll($repository);

        $loop = $this->eventLoop();
        Worker::$globalEvent = $loop;
        Worker::$logFile = $runDir.'/cable.log';
        Worker::$pidFile = $runDir.'/cable.pid';
        Timer::init($loop);
        TcpConnection::$defaultMaxSendBufferSize = self::MAX_SEND_BUFFER;

        $server = new CableServer(
            new ChannelRegistry($repository, $this->turboStreamName),
            $repository,
            $this->cookies,
            $this->clock,
            $this->logger,
            assumeSsl: $this->ssl->enabled,
            // actioncable's railtie allows localhost on any port in development
            allowedOrigins: 'dev' === $this->environment ? ['~\Ahttps?://localhost:\d+\z~'] : [],
        );

        $websocket = new Worker("tcp://$host:$port");
        $websocket->onConnect = function (TcpConnection $tcp) use ($server): void {
            $connection = $server->open(new TcpTransport($tcp));
            $tcp->onMessage = function (TcpConnection $tcp, string $data) use ($connection): void {
                $this->guard(fn () => $connection->onData($data));
            };
            $tcp->onClose = function () use ($connection): void {
                $this->guard(fn () => $connection->onClose());
            };
            // A client this far behind is dropped rather than skipped: it reconnects and reloads.
            $tcp->onBufferFull = function (TcpConnection $tcp): void {
                $this->logger->warning('Cable client too slow; closing its connection');
                $tcp->destroy();
            };
        };

        // The publish socket: length-prefixed ControlFrames. Every frame of one read is applied in a
        // batch, so a request's broadcasts reach each socket in one write.
        $control = new Worker('unix://'.$socket);
        $control->onConnect = function (TcpConnection $tcp) use ($server): void {
            $buffer = '';
            $tcp->onMessage = function (TcpConnection $tcp, string $data) use (&$buffer, $server): void {
                $buffer .= $data;
                $frames = [];
                while (\strlen($buffer) >= 4) {
                    $length = (int) (unpack('N', $buffer) ?: [1 => 0])[1];
                    if ($length < 5 || $length > self::MAX_CONTROL_FRAME) {
                        $this->logger->error('Invalid frame on the publish socket; dropping the connection');
                        $tcp->destroy();

                        return;
                    }
                    if (\strlen($buffer) < $length) {
                        break;
                    }
                    $frames[] = substr($buffer, 4, $length - 4);
                    $buffer = (string) substr($buffer, $length);
                }
                $this->guard(fn () => $server->batch(static function () use ($server, $frames): void {
                    foreach ($frames as $frame) {
                        $server->control($frame);
                    }
                }));
            };
        };

        try {
            $websocket->listen();
            $control->listen();
        } catch (\Throwable $error) {
            $io->error('Cannot listen: '.$error->getMessage());

            return Command::FAILURE;
        }
        @chmod($socket, 0o666);
        @file_put_contents(Worker::$pidFile, (string) getmypid());

        $loop->setErrorHandler(fn (\Throwable $error) => $this->logger->error('Cable event loop error: '.$error->getMessage(), ['exception' => $error]));
        $loop->repeat(Protocol::BEAT_INTERVAL, fn () => $this->guard($server->heartbeat(...)));
        $stop = function () use ($server, $loop): void {
            $this->logger->info('Cable server stopping');
            $this->guard($server->restart(...));
            $loop->delay(0.2, $loop->stop(...));
        };
        $loop->onSignal(\SIGTERM, $stop);
        $loop->onSignal(\SIGINT, $stop);

        $io->writeln(\sprintf('Action Cable listening on ws://%s:%d/cable (%s loop), publish socket %s', $host, $port, (new \ReflectionClass($loop))->getShortName(), $socket));
        $loop->run();

        @unlink($socket);
        @unlink(Worker::$pidFile);

        return Command::SUCCESS;
    }

    private function eventLoop(): EventInterface
    {
        return \extension_loaded('event') ? new Event() : new Select();
    }

    /** Removes a stale socket file; false when a live server still answers on it. */
    private function claimSocket(string $socket): bool
    {
        if (!file_exists($socket)) {
            return true;
        }
        $probe = @stream_socket_client('unix://'.$socket, $errno, $error, 0.1);
        if (false !== $probe) {
            fclose($probe);

            return false;
        }

        return @unlink($socket) || !file_exists($socket);
    }

    /** Membership.disconnect_all: nobody is connected to a server that just started. */
    private function disconnectAll(CableRepository $repository): void
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $repository->disconnectAll();

                return;
            } catch (LockWaitTimeoutException $locked) {
                if ($attempt >= 250) {
                    throw $locked;
                }
                usleep(20_000);
            }
        }
    }

    /** No exception may escape into Workerman, which would stop the process. */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            $this->logger->error('Cable server error: '.$error->getMessage(), ['exception' => $error]);
        }
    }
}
