<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Controller\Rails\HealthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

/**
 * Rails::HealthController (railties lib/rails/health_controller.rb).
 */
final class HealthControllerTest extends TestCase
{
    private const string GREEN = '<!DOCTYPE html><html><body style="background-color: green"></body></html>';
    private const string JSON = '{"status":"up","timestamp":"2026-01-01T12:00:00Z"}';

    /**
     * @return iterable<string, array{?string, ?string, bool, string, string, ?string}>
     */
    public static function requests(): iterable
    {
        yield 'no accept' => [null, null, false, self::GREEN, 'text/html; charset=utf-8', null];
        yield 'browser accept' => [null, 'text/html,application/xhtml+xml,*/*;q=0.8', false, self::GREEN, 'text/html; charset=utf-8', null];
        yield 'json accept' => [null, 'application/json', false, self::JSON, 'application/json; charset=utf-8', 'Accept'];
        yield 'any accept' => [null, '*/*', false, self::GREEN, 'text/html; charset=utf-8', 'Accept'];
        yield 'q-ordered accept' => [null, 'text/html;q=0.5, application/json', false, self::JSON, 'application/json; charset=utf-8', 'Accept'];
        yield 'json format' => ['json', 'text/html', false, self::JSON, 'application/json; charset=utf-8', null];
        yield 'html format' => ['html', 'application/json', false, self::GREEN, 'text/html; charset=utf-8', null];
    }

    #[DataProvider('requests')]
    public function testUp(?string $format, ?string $accept, bool $xhr, string $body, string $contentType, ?string $vary): void
    {
        $response = $this->controller()->show($this->request($format, $accept, $xhr));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, $response->getContent());
        self::assertSame($contentType, $response->headers->get('Content-Type'));
        self::assertSame($vary, $response->headers->get('Vary'));
    }

    /**
     * @return iterable<string, array{?string, ?string, bool}>
     */
    public static function unknownFormats(): iterable
    {
        yield 'xml format' => ['xml', null, false];
        yield 'unknown format' => ['foo', null, false];
        yield 'xml accept' => [null, 'application/xml', false];
        yield 'xhr without accept' => [null, null, true];
    }

    #[DataProvider('unknownFormats')]
    public function testUnknownFormatIsNotAcceptable(?string $format, ?string $accept, bool $xhr): void
    {
        $this->expectException(NotAcceptableHttpException::class);

        $this->controller()->show($this->request($format, $accept, $xhr));
    }

    private function controller(): HealthController
    {
        return new HealthController(new MockClock('2026-01-01 12:00:00.123456', 'UTC'));
    }

    private function request(?string $format, ?string $accept, bool $xhr): Request
    {
        $request = Request::create('/up');
        $request->headers->remove('Accept');
        if (null !== $accept) {
            $request->headers->set('Accept', $accept);
        }
        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
        $request->attributes->set('_format', $format);

        return $request;
    }
}
