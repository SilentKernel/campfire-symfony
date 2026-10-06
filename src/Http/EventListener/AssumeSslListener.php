<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use App\Http\Ssl;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ActionDispatch::AssumeSSL: unless DISABLE_SSL, every request is treated as HTTPS (scheme,
 * request.base_url, generated URLs, the CSRF origin check). Runs before routing sets the
 * request context.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
final readonly class AssumeSslListener
{
    public function __construct(private Ssl $ssl)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->ssl->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $request->server->set('HTTPS', 'on');
        if ($request->headers->has('X-Forwarded-Proto')) {
            $request->headers->set('X-Forwarded-Proto', 'https');
        }
    }
}
