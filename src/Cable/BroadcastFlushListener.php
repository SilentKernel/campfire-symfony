<?php

declare(strict_types=1);

namespace App\Cable;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Sends SocketBroadcaster's buffered publications once a unit of work is done: before the HTTP
 * response goes out (so clients get the broadcast along with the response, as from Rails), after
 * each job, and when a console command ends.
 */
#[When(env: 'dev')]
#[When(env: 'prod')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'flush', priority: -4096)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'flush')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'flush')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'flush')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'flush')]
final readonly class BroadcastFlushListener
{
    public function __construct(private SocketBroadcaster $broadcaster)
    {
    }

    public function flush(): void
    {
        $this->broadcaster->flush();
    }
}
