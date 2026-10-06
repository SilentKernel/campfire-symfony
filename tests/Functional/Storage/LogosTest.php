<?php

declare(strict_types=1);

namespace App\Tests\Functional\Storage;

use App\Storage\Attachments;
use App\Storage\BlobService;

final class LogosTest extends StorageTestCase
{
    private const string ICONS = '/reference/app/assets/images/logos/';

    public function testTheStockIconsWithoutALogo(): void
    {
        $client = $this->client();
        $client->request('GET', '/account/logo?size=small');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('max-age=300, public, stale-while-revalidate=604800', $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        // The reference's ETag for the seed account.
        self::assertSame('W/"ba406155241302571a06615e754be1d0"', $response->headers->get('ETag'));
        self::assertSame("inline; filename=\"app-icon-192.png\"; filename*=UTF-8''app-icon-192.png", $response->headers->get('Content-Disposition'));
        self::assertSame(md5_file(\dirname(__DIR__, 3).self::ICONS.'app-icon-192.png'), md5((string) $client->getInternalResponse()->getContent()));

        $client->request('GET', '/account/logo');
        self::assertSame(md5_file(\dirname(__DIR__, 3).self::ICONS.'app-icon.png'), md5((string) $client->getInternalResponse()->getContent()));

        $client->request('GET', '/account/logo', server: ['HTTP_IF_NONE_MATCH' => 'W/"ba406155241302571a06615e754be1d0"']);
        self::assertSame(304, $client->getResponse()->getStatusCode());
    }

    public function testAnUploadedLogoIsServedAsPngVariants(): void
    {
        $client = $this->client();
        $container = static::getContainer();
        $blob = $container->get(BlobService::class)->createFromFile(\dirname(__DIR__, 3).'/reference/test/fixtures/files/black_hole.jpg', 'black_hole.jpg', 'image/jpeg');
        $accountId = (int) $this->connection()->fetchOne('SELECT id FROM accounts');
        $container->get(Attachments::class)->attach('Account', $accountId, 'logo', $blob);

        foreach (['large' => [512, 288], 'small' => [192, 108]] as $size => $dimensions) {
            $client->request('GET', '/account/logo'.('small' === $size ? '?size=small' : ''));
            $response = $client->getResponse();
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('image/png', $response->headers->get('Content-Type'));
            $image = getimagesizefromstring((string) $client->getInternalResponse()->getContent());
            self::assertSame($dimensions, [$image[0] ?? 0, $image[1] ?? 0], $size);
        }
        $digests = $this->connection()->fetchFirstColumn('SELECT variation_digest FROM active_storage_variant_records WHERE blob_id = ? ORDER BY id', [$blob->getId()]);
        // tests/vectors/storage.json logos: black_hole-logo-large, -small
        self::assertSame(['ksXvpLsa7BuCVyHOwmQOKedbIbM=', 'EXATZfM7OVVC86CDh85TOsa8ZSw='], $digests);
    }

    public function testOnlyAdministratorsDestroyTheLogo(): void
    {
        $client = $this->client();
        $token = self::signInWithCsrf($client);
        $container = static::getContainer();
        $blob = $container->get(BlobService::class)->createFromBytes((string) file_get_contents(\dirname(__DIR__, 3).'/reference/test/fixtures/files/moon.jpg'), 'moon.jpg');
        $accountId = (int) $this->connection()->fetchOne('SELECT id FROM accounts');
        $container->get(Attachments::class)->attach('Account', $accountId, 'logo', $blob);

        $client->request('DELETE', '/account/logo', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/account/edit', $client->getResponse()->headers->get('Location'));
        self::assertNull($container->get(Attachments::class)->find('Account', $accountId, 'logo'));
    }
}
