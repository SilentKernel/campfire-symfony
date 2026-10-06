<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActiveStorage;

use App\Entity\ActiveStorage\Blob;
use App\Storage\StorageUrls;
use App\Tests\Functional\Storage\StorageTestCase;

final class RepresentationsTest extends StorageTestCase
{
    /** The path the reference renders for messages.image's thumbnail. */
    private const string MOON_THUMB_PATH = '/rails/active_storage/representations/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad/eyJfcmFpbHMiOnsiZGF0YSI6eyJmb3JtYXQiOiJqcGciLCJyZXNpemVfdG9fbGltaXQiOlsxMjAwLDgwMF19LCJwdXIiOiJ2YXJpYXRpb24ifX0=--28426ca1e33b0fea71b8b10b7f52a844de5886cf/moon.jpg';

    /** The poster the reference renders for messages.video. */
    private const string POSTER_PATH = '/rails/active_storage/representations/redirect/eyJfcmFpbHMiOnsiZGF0YSI6OSwicHVyIjoiYmxvYl9pZCJ9fQ==--c41e212f6aa839f5dc122c08987d3d9c13a372a0/eyJfcmFpbHMiOnsiZGF0YSI6eyJmb3JtYXQiOiJ3ZWJwIiwicmVzaXplX3RvX2xpbWl0IjpbMTIwMCw4MDBdfSwicHVyIjoidmFyaWF0aW9uIn19--132e54230de6ddc9c41e0118730416f2e3d3127f/alpha-centuri.mov';

    public function testAnExistingVariantRedirectsToItsImage(): void
    {
        $client = $this->client();
        $client->request('GET', self::MOON_THUMB_PATH);
        $response = $client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('max-age=300, private', $response->headers->get('Cache-Control'));

        $client->request('GET', (string) $response->headers->get('Location'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(md5_file($this->file(self::MOON_THUMB_KEY)), md5((string) $client->getInternalResponse()->getContent()));
        self::assertSame(6, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_variant_records'), 'no new variant');
    }

    public function testTheProxyStreamsTheVariant(): void
    {
        $client = $this->client();
        $client->request('GET', str_replace('/redirect/', '/proxy/', self::MOON_THUMB_PATH));
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('max-age=3155695200, public, immutable', $response->headers->get('Cache-Control'));
        self::assertSame(md5_file($this->file(self::MOON_THUMB_KEY)), md5((string) $client->getInternalResponse()->getContent()));
    }

    public function testANewVariationIsProcessedAndRecorded(): void
    {
        $client = $this->client();
        $urls = static::getContainer()->get(StorageUrls::class);
        $blob = $this->em()->find(Blob::class, 5);
        $path = $urls->variantPath($blob, ['resize_to_limit' => [100, 100]]);
        $client->request('GET', $path);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // Variation.decode: the URL's transformations carry strings, digested as such.
        $digest = $this->connection()->fetchOne('SELECT variation_digest FROM active_storage_variant_records WHERE blob_id = 5 ORDER BY id DESC LIMIT 1');
        self::assertNotSame('IBhrLAIapu+NCId+2Kz6EqUWRKY=', $digest);
        $image = $this->connection()->fetchAssociative("SELECT b.* FROM active_storage_blobs b JOIN active_storage_attachments a ON a.blob_id = b.id WHERE a.record_type = 'ActiveStorage::VariantRecord' ORDER BY a.id DESC LIMIT 1");
        self::assertSame('moon.jpg', $image['filename']);
        self::assertSame('image/jpeg', $image['content_type']);
        self::assertFileExists($this->file($image['key']));
        [$width, $height] = getimagesize($this->file($image['key'])) ?: [0, 0];
        self::assertSame([100, 100], [$width, $height]);

        $client->request('GET', $path);
        self::assertSame(7, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_variant_records'), 'processed once');
    }

    public function testTheVideoPosterIsAPreviewVariant(): void
    {
        if (!\App\Storage\Processing\VideoPreviewer::ffmpegExists()) {
            self::markTestSkipped('ffmpeg is not installed');
        }
        $client = $this->client();
        $client->request('GET', self::POSTER_PATH);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        // The preview image (blob 10) already exists; the decoded variation is digested with strings.
        self::assertSame('u0TSbUypNLiezYYkiqbZ8jAyVv0=', $this->connection()->fetchOne('SELECT variation_digest FROM active_storage_variant_records WHERE blob_id = 10 ORDER BY id DESC LIMIT 1'));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM active_storage_attachments WHERE name = 'preview_image'"));
    }

    public function testAnUnrepresentableBlobFails(): void
    {
        $client = $this->client();
        $urls = static::getContainer()->get(StorageUrls::class);
        $bmp = $this->em()->find(Blob::class, 14);
        $client->request('GET', '/rails/active_storage/representations/redirect/'.$urls->signedId($bmp).'/'.(new \App\Storage\Variation(['format' => 'png']))->key(static::getContainer()->get(\App\Storage\DiskService::class)->verifier).'/pixel.bmp');
        self::assertSame(500, $client->getResponse()->getStatusCode());
    }
}
