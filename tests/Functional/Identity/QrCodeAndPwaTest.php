<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

/** QrCodeController and PwaController. */
final class QrCodeAndPwaTest extends IdentityTestCase
{
    public function testQrCodeIsTheSvgRqrcodeDraws(): void
    {
        $url = 'http://127.0.0.1:3291/join/CRMu-l8Ge-KB9B';
        $response = $this->get($this->client(), '/qr_code/'.strtr(base64_encode($url), '+/', '-_'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('max-age=31556952, public', $response->headers->get('Cache-Control'));
        // tests/Unit/Platform/fixtures/rqrcode_svg.json, rendered by the Rails app's rqrcode.
        self::assertSame('4c5450d96297d0d58343510cd21bd57ed7931b9a76b6d39360f13317406e73f0', hash('sha256', (string) $response->getContent()));
    }

    public function testManifest(): void
    {
        $response = $this->get($this->client(), '/webmanifest.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringStartsWith("{\n  \"name\": \"37signals\",", $body);
        // ERB's HTML escaping, as Rails serves it.
        self::assertStringContainsString('"src": "/account/logo?size=small&amp;v=20260101160000",', $body);
        self::assertStringContainsString('"src": "http://localhost/assets/screenshots/android-chat-', $body);
    }

    public function testServiceWorker(): void
    {
        $response = $this->get($this->client(), '/service-worker.js');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/javascript; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame(file_get_contents(\dirname(__DIR__, 3).'/reference/app/views/pwa/service_worker.js'), $response->getContent());
    }

    public function testPwaEndpointsOnlyServeTheirFormat(): void
    {
        $client = $this->client();
        self::assertSame(406, $this->get($client, '/webmanifest', ['HTTP_ACCEPT' => 'text/html'])->getStatusCode());
        self::assertSame(200, $this->get($client, '/webmanifest', ['HTTP_ACCEPT' => '*/*'])->getStatusCode());
        self::assertSame(406, $this->get($client, '/service-worker.json')->getStatusCode());
    }
}
