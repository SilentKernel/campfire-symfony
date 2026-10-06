<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Exception\UnknownFormat;
use App\Http\Mime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MimeTest extends TestCase
{
    /** @return iterable<string, array{?string, string, bool, list<string>}> */
    public static function requests(): iterable
    {
        $chrome = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
        yield 'browser' => [$chrome, '/rooms/1', false, ['html']];
        yield 'no accept' => [null, '/rooms/1', false, ['html']];
        yield 'wildcard' => ['*/*', '/rooms/1', false, ['*/*']];
        yield 'turbo form' => ['text/vnd.turbo-stream.html, text/html, application/xhtml+xml', '/rooms/1/messages', false, ['turbo_stream', 'html']];
        yield 'quality' => ['text/html;q=0.5, application/json', '/', false, ['json', 'html']];
        yield 'browser-like with json' => ['application/json, */*', '/', false, ['html']];
        yield 'equal q keeps order' => ['application/json;q=0.1,text/html;q=0.1', '/', false, ['json', 'html']];
        yield 'xhr' => [null, '/', true, ['js']];
        yield 'xhr with accept' => ['text/html,application/xml;q=0.9,*/*;q=0.8', '/', true, ['html', 'xml', '*/*']];
        yield 'path extension' => [null, '/rooms/1.json', false, ['json']];
        yield 'text star' => ['text/*', '/', false, ['html', 'text', 'js', 'css', 'ics', 'csv', 'vcf', 'vtt', 'md', 'xml', 'yaml', 'json', 'turbo_stream']];
    }

    /** @param list<string> $expected */
    #[DataProvider('requests')]
    public function testFormats(?string $accept, string $path, bool $xhr, array $expected): void
    {
        $server = null === $accept ? [] : ['HTTP_ACCEPT' => $accept];
        if ($xhr) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $request = Request::create($path, 'GET', [], [], [], $server);
        if (null === $accept) {
            $request->headers->remove('Accept');
        }

        self::assertSame($expected, Mime::formats($request));
    }

    public function testFormatParamWins(): void
    {
        $request = Request::create('/rooms/1.json', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $request->attributes->set('_route_params', ['_format' => 'json']);

        self::assertSame(['json'], Mime::formats($request));
        self::assertFalse(Mime::shouldApplyVaryHeader($request));
    }

    public function testRespondTo(): void
    {
        $turbo = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertSame('turbo_stream', Mime::respondTo($turbo, ['html', 'turbo_stream']));
        self::assertTrue(Mime::shouldApplyVaryHeader($turbo));

        $any = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => '*/*']);
        self::assertSame('html', Mime::respondTo($any, ['html', 'json']));

        $this->expectException(UnknownFormat::class);
        Mime::respondTo(Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), ['html']);
    }
}
