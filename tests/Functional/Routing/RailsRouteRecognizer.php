<?php

declare(strict_types=1);

namespace App\Tests\Functional\Routing;

use App\Http\Attribute\ActionNotFound;
use App\Http\EventListener\RailsRoutingListener;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Recognizes a request path the way Rails' Journey router does, using the Symfony routes:
 * the path is normalized (Journey::Router::Utils.normalize_path), segments are matched on the
 * still-escaped path and parameters are unescaped afterwards (so "a%2Fb" is one segment).
 * Symfony's UrlMatcher decodes before matching, hence the "%" → "%25" escaping.
 */
final readonly class RailsRouteRecognizer
{
    public function __construct(private RouteCollection $routes)
    {
    }

    public static function normalizePath(string $path): string
    {
        return RailsRoutingListener::normalizePath($path);
    }

    /**
     * @return array{endpoint: string, params: array<string, string>}|null null when Rails would not route it
     */
    public function recognize(string $verb, string $path): ?array
    {
        $matcher = new UrlMatcher($this->routes, new RequestContext(method: $verb));

        try {
            $match = $matcher->match(str_replace('%', '%25', self::normalizePath($path)));
        } catch (ResourceNotFoundException|MethodNotAllowedException) {
            return null;
        }

        $endpoint = self::endpoint($match['_controller']);
        if (null === $endpoint) {
            return null;
        }

        $params = [];
        foreach ($match as $key => $value) {
            if (null === $value || ('_' === $key[0] && '_format' !== $key)) {
                continue;
            }
            $params['_format' === $key ? 'format' : $key] = rawurldecode((string) $value);
        }

        return ['endpoint' => $endpoint, 'params' => $params];
    }

    /**
     * "App\Controller\Rooms\OpensController::show" → "rooms/opens#show"; null for a controller
     * Rails does not have (#[ActionNotFound] on the class).
     */
    public static function endpoint(string $controller): ?string
    {
        [$class, $method] = explode('::', $controller);
        if ([] !== new \ReflectionClass($class)->getAttributes(ActionNotFound::class)) {
            return null;
        }

        $segments = explode('\\', substr($class, \strlen('App\\Controller\\')));
        $segments[] = substr(array_pop($segments), 0, -\strlen('Controller'));

        return implode('/', array_map(self::underscore(...), $segments)).'#'.self::underscore($method);
    }

    private static function underscore(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $camel));
    }
}
