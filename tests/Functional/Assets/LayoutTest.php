<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assets;

use App\Entity\Account;
use App\Entity\User;
use App\Tests\Unit\Twig\FakeViewContext;
use App\Tests\Unit\Twig\Records;
use App\Twig\ApplicationExtension;
use App\Twig\Asset\Assets;
use App\Twig\AssetExtension;
use App\Twig\AvatarsExtension;
use App\Twig\Escaper\ErbEscaperExtension;
use App\Twig\HelpersExtension;
use App\Twig\TagExtension;
use App\Twig\TurboExtension;
use App\Twig\View\ViewHelpers;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * templates/layouts/application.html.twig against pages the reference app rendered
 * (tests/fixtures/rails/layout/: GET /session/new signed out, GET /rooms/:hq as David), with the
 * request state of those pages. Digests and CSRF tokens are normalized; everything else,
 * whitespace included, must be identical.
 */
final class LayoutTest extends KernelTestCase
{
    private const CHILD = <<<'TWIG'
        {% extends 'layouts/application.html.twig' %}
        {% set page_title = title %}
        {% set body_class = body_class %}
        {% block head %}{{ head|raw }}{% endblock %}
        {% block content %}CONTENT{% endblock %}
        TWIG;

    public function testSignedOutLayoutMatchesRails(): void
    {
        $context = new FakeViewContext(account: $this->account());
        $html = $this->render($context, ['title' => 'Sign in', 'body_class' => null, 'head' => '<meta name="turbo-visit-control" content="reload">']);
        $rails = $this->rails('session_new.html');

        self::assertSame(self::head($rails), self::head($html));
        self::assertSame(self::bodyBefore($rails), self::bodyBefore($html));
        self::assertSame(self::bodyAfter($rails), self::bodyAfter($html));
    }

    public function testSignedInHeadMatchesRails(): void
    {
        $david = Records::saved(new User('David'), 127326141);
        $david->setRole(\App\Entity\Enum\UserRole::Administrator);
        $context = new FakeViewContext(user: $david, account: $this->account());
        $html = $this->render($context, [
            'title' => 'HQ',
            'body_class' => 'sidebar',
            'head' => "<meta name=\"turbo-cache-control\" content=\"no-preview\">  \n  <meta name=\"current-room-id\" content=\"201306877\">\n",
        ]);
        $rails = $this->rails('room_show.html');

        self::assertSame(self::head($rails), self::head($html));
        self::assertStringContainsString('<body class="sidebar admin" data-controller="local-time lightbox">', $html);
    }

    public function testFlash(): void
    {
        $html = $this->render(new FakeViewContext(account: $this->account(), flash: ['alert' => 'It\'s gone']), ['title' => null, 'body_class' => null, 'head' => '']);

        self::assertStringContainsString(
            "    <div class=\"flash\" data-controller=\"element-removal\" data-action=\"animationend->element-removal#remove\">\n"
            ."        <div class=\"flash__inner shadow\" style=\"--flash-background: var(--color-negative)\">\n"
            ."            <img aria-hidden=\"true\" class=\"colorize--white\" src=\"/assets/{alert.svg}\" width=\"24\" height=\"24\" /></span>\n"
            ."        </div>\n"
            ."        <span class=\"for-screen-reader\" role=\"alert\" aria-atomic=\"true\">It&#39;s gone</span>\n"
            ."      </div>\n\n    <main",
            $html,
        );
        self::assertStringContainsString('<title>Campfire</title>', $html);
    }

    /** @param array<string, mixed> $variables */
    private function render(FakeViewContext $context, array $variables): string
    {
        $assets = self::getContainer()->get(Assets::class);
        \assert($assets instanceof Assets);
        $helpers = new ViewHelpers($assets, $context);
        $urls = $this->urls();

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['child.html.twig' => self::CHILD]),
            new FilesystemLoader(self::getContainer()->getParameter('kernel.project_dir').'/templates'),
        ]), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addExtension(new ErbEscaperExtension());
        $twig->addExtension(new RoutingExtension($urls));
        $twig->addExtension(new AssetExtension($assets, $helpers, $context));
        $twig->addExtension(new TagExtension($helpers));
        $twig->addExtension(new TurboExtension($context));
        $twig->addExtension(new ApplicationExtension($context, $helpers, $urls));
        $twig->addExtension(new AvatarsExtension($context, $helpers, $urls));
        $twig->addExtension(new HelpersExtension($helpers, $assets, $urls));

        return RailsAssets::normalizeOurs($twig->render('child.html.twig', $variables), $this->assetMapper());
    }

    private function rails(string $page): string
    {
        $html = (string) file_get_contents(__DIR__.'/../../fixtures/rails/layout/'.$page);

        return (string) preg_replace('~(name="csrf-token" content=")[^"]+~', '$1masked-token', RailsAssets::normalizeRails($html));
    }

    private function account(): Account
    {
        return Records::saved(new Account('37signals', 'join'), 1, new \DateTimeImmutable('2026-01-01 16:00:00 UTC'));
    }

    private function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('root', new Route('/'));
        $routes->add('webmanifest', new Route('/webmanifest.{_format}'));
        $routes->add('account_logo', new Route('/account/logo'));
        $routes->add('user', new Route('/users/{id}'));
        $routes->add('user_avatar', new Route('/users/{user_id}/avatar'));

        return new UrlGenerator($routes, new RequestContext());
    }

    private function assetMapper(): AssetMapperInterface
    {
        $mapper = self::getContainer()->get(AssetMapperInterface::class);
        \assert($mapper instanceof AssetMapperInterface);

        return $mapper;
    }

    private static function head(string $html): string
    {
        return substr($html, 0, (int) strpos($html, '</head>') + \strlen('</head>'));
    }

    private static function bodyBefore(string $html): string
    {
        $start = (int) strpos($html, '</head>');

        return substr($html, $start, (int) strpos($html, '<main id="main-content">') - $start);
    }

    private static function bodyAfter(string $html): string
    {
        return substr($html, (int) strpos($html, '      <footer id="footer">'));
    }
}
