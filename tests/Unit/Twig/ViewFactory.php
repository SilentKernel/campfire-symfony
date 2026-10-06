<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\Asset\Assets;
use App\Twig\Asset\PreloadLinks;
use App\Twig\View\ViewHelpers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** Real helper services over test doubles: assets resolve to the reference app's digested names. */
final class ViewFactory
{
    /** Digests the reference app uses (tests/fixtures/rails/assets/manifest.json). */
    public static function assets(TestCase $test): Assets
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/rails/assets/manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
        $mapper = (new \ReflectionMethod($test, 'createStub'))->invoke($test, AssetMapperInterface::class);
        $mapper->method('getPublicPath')->willReturnCallback(static fn (string $path): ?string => isset($manifest[$path]) ? '/assets/'.$manifest[$path]['digested_path'] : null);

        return new Assets(
            $mapper,
            new AssetMapperRepository([], sys_get_temp_dir()),
            new ImportMapConfigReader(sys_get_temp_dir().'/none-importmap.php', new RemotePackageStorage(sys_get_temp_dir())),
            new PreloadLinks(),
        );
    }

    public static function helpers(TestCase $test, FakeViewContext $context): ViewHelpers
    {
        return new ViewHelpers(self::assets($test), $context);
    }

    /** The Rails routes the helpers generate, with Rails' parameter names. */
    public static function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('root', new Route('/'));
        $routes->add('user', new Route('/users/{id}'));
        $routes->add('user_avatar', new Route('/users/{user_id}/avatar'));
        $routes->add('account_logo', new Route('/account/logo'));
        $routes->add('qr_code', new Route('/qr_code/{id}'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
