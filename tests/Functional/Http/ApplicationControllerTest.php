<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Controller\ApplicationController;
use App\Http\Current;
use App\Http\Exception\UnknownFormat;
use App\Http\Exception\UnsafeRedirect;
use App\Http\Flash;
use App\Http\Halt;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationControllerTest extends HttpTestCase
{
    private object $controller;
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->request = Request::create('http://campfire.test/rooms/1', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml', 'HTTP_REFERER' => 'http://campfire.test/rooms/2']);
        self::getContainer()->get(RequestStack::class)->push($this->request);
        self::getContainer()->get(Current::class)->setRequest($this->request);

        $this->controller = new class extends ApplicationController {
            /** @param list<mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                return $this->{$name}(...$arguments);
            }
        };
        $this->controller->setContainer(self::getContainer());
    }

    public function testRedirects(): void
    {
        $response = $this->controller->redirectTo('/rooms/3', notice: 'Saved');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://campfire.test/rooms/3', $response->headers->get('Location'));
        self::assertSame('', $response->getContent());
        self::assertSame('Saved', self::getContainer()->get(Flash::class)->get('notice'));

        self::assertSame('http://campfire.test/rooms/2', $this->controller->redirectBackOr('/')->headers->get('Location'));
        self::assertSame(303, $this->controller->redirectTo('http://campfire.test/x', 303)->getStatusCode());
        self::assertSame('https://elsewhere.test/', $this->controller->redirectTo('https://elsewhere.test/', allowOtherHost: true)->headers->get('Location'));

        $this->expectException(UnsafeRedirect::class);
        $this->controller->redirectTo('https://elsewhere.test/');
    }

    public function testRedirectBackIgnoresOtherHosts(): void
    {
        $this->request->headers->set('Referer', 'https://evil.test/');
        self::assertSame('http://campfire.test/', $this->controller->redirectBackOr('/')->headers->get('Location'));
    }

    public function testRespondTo(): void
    {
        $response = $this->controller->respondTo($this->request, [
            'html' => static fn (): Response => new Response('html'),
            'turbo_stream' => static fn (): Response => new Response('stream'),
        ]);
        self::assertSame('stream', $response->getContent());
        self::assertSame('Accept', $response->headers->get('Vary'));

        $this->expectException(UnknownFormat::class);
        $this->controller->respondTo($this->request, ['json' => static fn (): Response => new Response('{}')]);
    }

    public function testHeadAndTurboStream(): void
    {
        self::assertSame('text/vnd.turbo-stream.html', $this->controller->head(200)->headers->get('Content-Type'));
        self::assertFalse($this->controller->head(204)->headers->has('Content-Type'));
        self::assertSame('text/vnd.turbo-stream.html; charset=utf-8', $this->controller->turboStream('<turbo-stream></turbo-stream>')->headers->get('Content-Type'));
    }

    public function testRequireAdministrator(): void
    {
        $current = self::getContainer()->get(Current::class);
        $current->setUser(self::getContainer()->get(UserRepository::class)->find(self::id('users.david')));
        $this->controller->requireAdministrator();

        $current->setUser(self::getContainer()->get(UserRepository::class)->find(self::id('users.kevin')));
        try {
            $this->controller->requireAdministrator();
            self::fail('Expected a halt');
        } catch (Halt $halt) {
            self::assertSame(403, $halt->response->getStatusCode());
        }
    }
}
