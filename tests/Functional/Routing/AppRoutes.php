<?php

declare(strict_types=1);

namespace App\Tests\Functional\Routing;

use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads config/routes.yaml exactly as the framework does ("routing.controllers" = every
 * instantiable class under src/Controller: no abstract bases, traits or interfaces), without
 * booting the kernel, so routing is testable on its own.
 */
final class AppRoutes
{
    public static function load(): RouteCollection
    {
        $projectDir = \dirname(__DIR__, 3);

        $controllers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/src/Controller', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), \strlen($projectDir.'/src/'), -\strlen('.php'));
            $class = 'App\\'.str_replace('/', '\\', $relative);
            if (new \ReflectionClass($class)->isInstantiable()) {
                $controllers[] = $class;
            }
        }
        sort($controllers);

        $yaml = new YamlFileLoader(new FileLocator($projectDir.'/config'));
        new LoaderResolver([$yaml, new AttributeServicesLoader($controllers), new AttributeRouteControllerLoader('test')]);

        return $yaml->load('routes.yaml');
    }
}
