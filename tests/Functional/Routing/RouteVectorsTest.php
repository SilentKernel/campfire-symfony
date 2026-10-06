<?php

declare(strict_types=1);

namespace App\Tests\Functional\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of config/routes.yaml against tests/vectors/campfire_routes.json and `bin/rails routes`
 * (rails_routes.txt, captured from the campfire-reference image).
 */
final class RouteVectorsTest extends TestCase
{
    private const array SAMPLE_PARAMS = [
        'id' => '7',
        'room_id' => '1',
        'message_id' => '42',
        'user_id' => '3',
        'bot_id' => '5',
        'bot_key' => '5-abcDEF',
        'join_code' => 'abc-def-ghi',
        'push_subscription_id' => '4',
        'signed_id' => 'eyJfcmFpbHMiOnt9fQ--0a1b',
        'signed_blob_id' => 'eyJfcmFpbHMiOnt9fQ--0a1b',
        'variation_key' => 'eyJfcmFpbHMiOnt9fQ--2c3d',
        'encoded_key' => 'eyJfcmFpbHMiOnt9fQ--4e5f',
        'encoded_token' => 'eyJfcmFpbHMiOnt9fQ--6a7b',
        'inbound_email_id' => '9',
        'filename' => 'dir/photo.tar',
    ];

    private RouteCollection $routes;

    private UrlGenerator $generator;

    protected function setUp(): void
    {
        $this->routes = AppRoutes::load();
        $this->generator = new UrlGenerator($this->routes, new RequestContext());
    }

    /**
     * @return iterable<string, array{string, string, ?string, array<string, string>}>
     */
    public static function recognitions(): iterable
    {
        foreach (self::vectors()['recognitions'] as $r) {
            yield $r['verb'].' '.$r['path'] => [$r['verb'], $r['path'], $r['endpoint'], $r['params']];
        }
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('recognitions')]
    public function testRecognizesLikeRails(string $verb, string $path, ?string $endpoint, array $params): void
    {
        $recognized = new RailsRouteRecognizer($this->routes)->recognize($verb, $path);

        if (null === $endpoint) {
            self::assertNull($recognized);

            return;
        }

        self::assertNotNull($recognized);
        self::assertSame($endpoint, $recognized['endpoint']);
        ksort($params);
        ksort($recognized['params']);
        self::assertSame($params, $recognized['params']);
    }

    public function testEveryRailsRouteExistsWithItsVerbPathEndpointAndDefaults(): void
    {
        $pairs = [];
        foreach ($this->appRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $pairs[] = $method.' '.$route->getPath();
            }
        }

        $vectors = self::vectors()['routes'];
        self::assertCount(\count($vectors), $pairs, 'one Symfony route per Rails route and verb');

        foreach ($vectors as $vector) {
            $path = self::symfonyPath($vector['path']);
            $matches = array_filter(
                $this->appRoutes(),
                static fn (Route $route): bool => $route->getPath() === $path && \in_array($vector['verb'], $route->getMethods(), true),
            );
            self::assertCount(1, $matches, $vector['verb'].' '.$vector['path']);
            $route = array_values($matches)[0];

            $endpoint = RailsRouteRecognizer::endpoint($route->getDefault('_controller'));
            self::assertSame(
                'rooms/settings#show' === $vector['endpoint'] ? null : $vector['endpoint'],
                $endpoint,
                $vector['path'],
            );

            $defaults = [];
            foreach ($route->getDefaults() as $key => $value) {
                if (null !== $value && '_controller' !== $key) {
                    $defaults['_format' === $key ? 'format' : $key] = $value;
                }
            }
            self::assertEquals($vector['defaults'], $defaults, $vector['path']);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function namedRailsRoutes(): iterable
    {
        foreach (self::railsRoutesTable() as $row) {
            if (null !== $row['name']) {
                yield $row['name'] => [$row['name'], $row['verb'], $row['path'], $row['endpoint']];
            }
        }
    }

    #[DataProvider('namedRailsRoutes')]
    public function testRailsRouteNamesAndUrlGeneration(string $name, string $verb, string $railsPath, string $endpoint): void
    {
        $route = $this->routes->get($name);
        self::assertNotNull($route, $name);
        self::assertSame(self::symfonyPath($railsPath), $route->getPath());
        self::assertSame([$verb], $route->getMethods());
        if ('rooms/settings#show' !== $endpoint) {
            self::assertSame($endpoint, RailsRouteRecognizer::endpoint($route->getDefault('_controller')));
        }

        $params = array_intersect_key(self::SAMPLE_PARAMS, array_flip($route->compile()->getPathVariables()));
        $expected = preg_replace_callback(
            '/[:*]([a-z_]+)/',
            static fn (array $m): string => self::SAMPLE_PARAMS[$m[1]],
            str_replace('(.:format)', '', $railsPath),
        );
        self::assertSame($expected, $this->generator->generate($name, $params));

        if (str_contains($railsPath, '(.:format)')) {
            $format = 'json' === $route->getDefault('_format') ? 'txt' : 'json';
            self::assertSame(
                $expected.'.'.$format,
                $this->generator->generate($name, [...$params, '_format' => $format]),
            );
        }
    }

    public function testUnnamedRailsRoutesUseTheNamedPathWithTheVerb(): void
    {
        $lastNamed = [];
        foreach (self::railsRoutesTable() as $row) {
            if (null !== $row['name']) {
                $lastNamed[$row['path']] = $row['name'];
                continue;
            }
            if (!isset($lastNamed[$row['path']])) {
                continue; // the two legacy Active Storage paths, named *_legacy
            }
            $name = $lastNamed[$row['path']].'.'.strtolower($row['verb']);
            $route = $this->routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame([$row['verb']], $route->getMethods(), $name);
        }
    }

    public function testDefaultedUserIdGeneratesMe(): void
    {
        self::assertSame('/users/me/sidebar', $this->generator->generate('user_sidebar'));
        self::assertSame('/users/me/profile', $this->generator->generate('user_profile'));
    }

    public function testEveryRouteResolvesToAnExistingControllerMethod(): void
    {
        $routes = $this->appRoutes();
        self::assertNotEmpty($routes);

        foreach ($routes as $name => $route) {
            [$class, $method] = explode('::', $route->getDefault('_controller'));
            self::assertTrue(class_exists($class), $name);
            self::assertTrue(method_exists($class, $method), $name);
            self::assertTrue(new \ReflectionMethod($class, $method)->isPublic(), $name);
        }
    }

    /**
     * @return array<string, Route>
     */
    private function appRoutes(): array
    {
        return array_filter(
            $this->routes->all(),
            static fn (Route $route): bool => str_starts_with((string) $route->getDefault('_controller'), 'App\\Controller\\'),
        );
    }

    private static function symfonyPath(string $railsPath): string
    {
        return (string) preg_replace('/[:*]([a-z_]+)/', '{$1}', str_replace('(.:format)', '.{_format}', $railsPath));
    }

    /**
     * @return array{routes: list<array{verb: string, path: string, endpoint: string, defaults: array<string, string>}>, recognitions: list<array{verb: string, path: string, endpoint: ?string, params: array<string, string>}>}
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/vectors/campfire_routes.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{name: ?string, verb: string, path: string, endpoint: string}>
     */
    private static function railsRoutesTable(): array
    {
        $rows = [];
        $lines = file(__DIR__.'/rails_routes.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (\array_slice($lines, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $name = \in_array($parts[0], ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true) ? null : array_shift($parts);
            $rows[] = ['name' => $name, 'verb' => $parts[0], 'path' => $parts[1], 'endpoint' => $parts[2]];
        }

        return $rows;
    }
}
