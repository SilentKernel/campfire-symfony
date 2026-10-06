<?php

declare(strict_types=1);

namespace App\Http\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Routes the request the way Rails' Journey router does, before Symfony's RouterListener (which
 * then sees `_controller` and only sets the request context):
 *
 * - the path is normalized first (actionpack journey/router/utils.rb `normalize_path`: "//" squeezed,
 *   trailing "/" dropped, %xx upcased), so "/rooms/1/" is served instead of redirected;
 * - segments are matched on the still-escaped path and parameters unescaped afterwards, so
 *   "a%2Fb" is one segment (UrlMatcher decodes before matching, hence "%" → "%25");
 * - a path that exists for another verb is a RoutingError (404), not a 405.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 33)]
final readonly class RailsRoutingListener
{
    public function __construct(private RouterInterface $router)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has('_controller')) {
            return;
        }

        try {
            $this->router->getContext()->fromRequest($request);
        } catch (\UnexpectedValueException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e, $e->getCode());
        }

        $path = self::normalizePath($request->getPathInfo());
        try {
            $parameters = $this->router->match(str_replace('%', '%25', $path));
        } catch (ResourceNotFoundException|MethodNotAllowedException $e) {
            throw new NotFoundHttpException(\sprintf('No route matches [%s] "%s"', $request->getMethod(), $path), $e);
        }

        foreach ($parameters as $key => $value) {
            if (\is_string($value) && ('_' !== $key[0] || '_format' === $key)) {
                $parameters[$key] = rawurldecode($value);
            }
        }

        $request->attributes->add($parameters);
        unset($parameters['_route'], $parameters['_controller']);
        $request->attributes->set('_route_params', $parameters);
    }

    /** actionpack lib/action_dispatch/journey/router/utils.rb `normalize_path` */
    public static function normalizePath(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', '/'.$path);
        if ('/' !== $path) {
            $path = rtrim($path, '/');
            $path = (string) preg_replace_callback('/%[a-f0-9]{2}/', static fn (array $m): string => strtoupper($m[0]), $path);
        }

        return '' === $path ? '/' : $path;
    }
}
