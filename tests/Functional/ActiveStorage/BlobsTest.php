<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActiveStorage;

use App\Tests\Functional\Storage\StorageTestCase;

final class BlobsTest extends StorageTestCase
{
    private const string MOON_PATH = '/rails/active_storage/blobs/redirect/'.self::MOON_SIGNED_ID.'/moon.jpg';

    public function testRedirectsToAnExpiringDiskUrl(): void
    {
        $client = $this->client();
        $client->request('GET', self::MOON_PATH);
        $response = $client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('max-age=300, private', $response->headers->get('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('', $response->getContent());
        $location = (string) $response->headers->get('Location');
        self::assertMatchesRegularExpression('#^http://localhost/rails/active_storage/disk/[^/]+/moon\.jpg$#', $location);

        $client->request('GET', $location);
        $file = $client->getResponse();
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('image/jpeg', $file->headers->get('Content-Type'));
        self::assertSame("inline; filename=\"moon.jpg\"; filename*=UTF-8''moon.jpg", $file->headers->get('Content-Disposition'));
        self::assertSame('max-age=3600, public', $file->headers->get('Cache-Control'));
        self::assertSame([], $file->headers->getCookies());
        self::assertSame('12794', $file->headers->get('Content-Length'));
        self::assertNotNull($file->headers->get('Last-Modified'));
        self::assertSame(md5_file($this->file(self::MOON_KEY)), md5((string) $client->getInternalResponse()->getContent()));
    }

    public function testTheDispositionParameterReachesTheDiskUrl(): void
    {
        $client = $this->client();
        $client->request('GET', self::MOON_PATH.'?disposition=attachment');
        $client->request('GET', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame("attachment; filename=\"moon.jpg\"; filename*=UTF-8''moon.jpg", $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheLegacyRouteRedirectsToo(): void
    {
        $client = $this->client();
        $client->request('GET', '/rails/active_storage/blobs/'.self::MOON_SIGNED_ID.'/moon.jpg');
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    public function testABadSignatureIsNotFound(): void
    {
        $client = $this->client();
        foreach (['/rails/active_storage/blobs/redirect/bogus/moon.jpg', '/rails/active_storage/blobs/proxy/bogus/moon.jpg', '/rails/active_storage/representations/redirect/bogus/bogus/moon.jpg'] as $path) {
            $client->request('GET', $path);
            $response = $client->getResponse();
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('text/html', $response->headers->get('Content-Type'), $path);
            self::assertSame('', $response->getContent());
            self::assertSame('no-cache', $response->headers->get('Cache-Control'));
        }
    }

    public function testAValidSignatureForAMissingBlobIsTheNotFoundPage(): void
    {
        $client = $this->client();
        $signedId = static::getContainer()->get(\App\Storage\StorageUrls::class)->signedId(999999);
        $client->request('GET', '/rails/active_storage/blobs/redirect/'.$signedId.'/x.jpg');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testProxyStreamsTheBlobForever(): void
    {
        $client = $this->client();
        $path = '/rails/active_storage/blobs/proxy/'.self::MOON_SIGNED_ID.'/moon.jpg';
        $client->request('GET', $path);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=3155695200, public, immutable', $response->headers->get('Cache-Control'));
        // The reference's ETag for this very path.
        self::assertSame('W/"ab9a8912f562f1a8b0322f6bea1afa82"', $response->headers->get('ETag'));
        self::assertSame('Sat, 01 Jan 2011 00:00:00 GMT', $response->headers->get('Last-Modified'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertSame('12794', $response->headers->get('Content-Length'));
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame("inline; filename=\"moon.jpg\"; filename*=UTF-8''moon.jpg", $response->headers->get('Content-Disposition'));
        self::assertNull($response->headers->get('Set-Cookie'));
        self::assertSame(md5_file($this->file(self::MOON_KEY)), md5((string) $client->getInternalResponse()->getContent()));

        $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => 'W/"ab9a8912f562f1a8b0322f6bea1afa82"']);
        self::assertSame(304, $client->getResponse()->getStatusCode());
        self::assertSame('max-age=3155695200, public, immutable', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testProxyServesByteRanges(): void
    {
        $client = $this->client();
        $path = '/rails/active_storage/blobs/proxy/'.self::MOON_SIGNED_ID.'/moon.jpg';
        $bytes = (string) file_get_contents($this->file(self::MOON_KEY));

        $client->request('GET', $path, server: ['HTTP_RANGE' => 'bytes=0-9']);
        $response = $client->getResponse();
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 0-9/12794', $response->headers->get('Content-Range'));
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame(substr($bytes, 0, 10), $client->getInternalResponse()->getContent());

        $client->request('GET', $path, server: ['HTTP_RANGE' => 'bytes=0-1,-2']);
        $response = $client->getResponse();
        self::assertSame(206, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^multipart\/byteranges; boundary=[0-9a-f]{32}$/', (string) $response->headers->get('Content-Type'));
        $boundary = substr((string) $response->headers->get('Content-Type'), \strlen('multipart/byteranges; boundary='));
        $expected = "\r\n--$boundary\r\nContent-Type: image/jpeg\r\nContent-Range: bytes 0-1/12794\r\n\r\n".substr($bytes, 0, 2)
            ."\r\n--$boundary\r\nContent-Type: image/jpeg\r\nContent-Range: bytes 12792-12793/12794\r\n\r\n".substr($bytes, -2)
            ."\r\n--$boundary--\r\n";
        self::assertSame($expected, $client->getInternalResponse()->getContent());

        $client->request('GET', $path, server: ['HTTP_RANGE' => 'bytes=20000-']);
        self::assertSame(416, $client->getResponse()->getStatusCode());
    }

    public function testDiskServesRangesAndRejectsBadKeys(): void
    {
        $client = $this->client();
        $client->request('GET', self::MOON_PATH);
        $location = (string) $client->getResponse()->headers->get('Location');

        $client->request('GET', $location, server: ['HTTP_RANGE' => 'bytes=2-5']);
        $response = $client->getResponse();
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 2-5/12794', $response->headers->get('Content-Range'));
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame(substr((string) file_get_contents($this->file(self::MOON_KEY)), 2, 4), $client->getInternalResponse()->getContent());

        $client->request('GET', '/rails/active_storage/disk/bogus/moon.jpg');
        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('max-age=3600, public', $response->headers->get('Cache-Control'));
    }

    public function testDiskUrlsExpire(): void
    {
        $client = $this->client();
        $client->request('GET', self::MOON_PATH);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::ensureKernelShutdown();

        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:05:01Z');
        $client = static::createClient();
        $client->request('GET', $location);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
