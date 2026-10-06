<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use App\Http\ErrorPages;
use App\Http\Halt;
use App\Http\Pipeline;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Halt becomes its response before anything else sees it (it is control flow, so Symfony's
 * ErrorListener must not log it); any other exception becomes the public error page
 * (ActionDispatch::ShowExceptions), after Symfony logs it and before its own error renderer,
 * which stays in charge in the debug "dev" environment.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onHalt', priority: 64)]
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class ShowExceptionsListener
{
    public function __construct(
        private ErrorPages $errorPages,
        #[Autowire('%kernel.environment%')] private string $environment,
        #[Autowire('%kernel.debug%')] private bool $debug,
    ) {
    }

    public function onHalt(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof Halt) {
            return;
        }

        $event->getRequest()->attributes->set(Pipeline::HALTED, true);
        $event->setResponse($exception->response);
        $event->allowCustomResponseCode();
        $event->stopPropagation();
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if ($this->debug && 'dev' === $this->environment) {
            return;
        }

        $request->attributes->set(Pipeline::EXCEPTION, true);
        $event->setResponse($this->errorPages->render(ErrorPages::statusFor($exception), $request));
        $event->allowCustomResponseCode();
    }
}
