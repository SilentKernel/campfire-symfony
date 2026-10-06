<?php

declare(strict_types=1);

namespace App\Http\Platform;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SetPlatform (reference/app/controllers/concerns/set_platform.rb): the request's
 * ApplicationPlatform, built lazily from the User-Agent, for views (`platform()` in Twig).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final class SetPlatformListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->has(ApplicationPlatform::ATTRIBUTE)) {
            $request->attributes->set(ApplicationPlatform::ATTRIBUTE, new ApplicationPlatform($request->headers->get('User-Agent')));
        }
    }
}
