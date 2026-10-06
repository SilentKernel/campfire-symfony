<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ContentTypes;
use App\Storage\Filename;
use App\Storage\NamedVariants;
use App\Storage\Variation;
use App\Tests\Unit\Rails\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Signed ids, route paths and disk URLs against what the reference generated. */
final class UrlsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function messages(): iterable
    {
        foreach (StorageVectors::get('messages') as $message) {
            yield $message['fixture'] => [$message];
        }
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('messages')]
    public function testBlobPathsMatchRails(array $message): void
    {
        $urls = StorageVectors::urls();
        $blob = StorageVectors::blob($message['blob']);
        self::assertSame($message['rails_blob_path'], $urls->blobPath($blob));
        self::assertSame($message['rails_blob_download_path'], $urls->blobPath($blob, 'attachment'));
        self::assertSame($message['rails_blob_proxy_path'], $urls->blobProxyPath($blob));
        self::assertSame($blob->getId(), $urls->verifySignedId($urls->signedId($blob), Vectors::now()));

        $disk = StorageVectors::disk();
        foreach (['service_url' => null, 'service_url_attachment' => 'attachment'] as $key => $disposition) {
            $path = $disk->urlPath($blob->getKey(), null, new Filename($blob->getFilename()), ContentTypes::forServing($blob->getContentType()), ContentTypes::forcedDisposition($blob->getContentType()) ?? $disposition);
            self::assertSame($message[$key], 'http://campfire.test'.$path);
        }
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('messages')]
    public function testRepresentationPathsMatchRails(array $message): void
    {
        $urls = StorageVectors::urls();
        $blob = StorageVectors::blob($message['blob']);
        if (isset($message['thumb_path'])) {
            self::assertSame($message['thumb_path'], $urls->variantPath($blob, 'thumb'));
            $variation = NamedVariants::get('thumb')->defaultTo(['format' => \App\Storage\BlobService::defaultVariantFormat($blob)]);
            self::assertSame($message['thumb_proxy_path'], $urls->representationProxyPath($blob, $variation));
            self::assertSame($message['variants'][0]['variation_digest'], $variation->digest());
        }
        if (isset($message['poster_path'])) {
            self::assertSame($message['poster_path'], $urls->previewPath($blob));
            self::assertSame($message['poster_proxy_path'], $urls->representationProxyPath($blob, NamedVariants::poster()));
        }
        if (!$message['variable'] && !isset($message['poster_path'])) {
            self::assertFalse(\App\Storage\BlobService::isVariable($blob));
        }
    }

    public function testVideoPreviewVariantDigests(): void
    {
        foreach (StorageVectors::get('messages') as $message) {
            foreach ($message['variants'] ?? [] as $variant) {
                $variation = new Variation(StorageVectors::typed($variant['transformations_typed']));
                self::assertSame($variant['variation_digest'], $variation->digest(), $variant['label']);
            }
        }
        foreach ([...StorageVectors::get('avatars'), ...StorageVectors::get('logos')] as $vector) {
            $blob = StorageVectors::blob($vector['blob']);
            foreach ($vector['variants'] as $variant) {
                $name = match (true) {
                    str_ends_with($variant['label'], 'avatar') => 'square',
                    str_ends_with($variant['label'], 'large') => 'large',
                    default => 'small',
                };
                $variation = NamedVariants::get($name)->defaultTo(['format' => \App\Storage\BlobService::defaultVariantFormat($blob)]);
                self::assertSame($variant['variation_digest'], $variation->digest(), $variant['label']);
            }
        }
    }

    public function testAvatarVariantPath(): void
    {
        $avatar = StorageVectors::get('avatars')[0];
        self::assertSame($avatar['path'], StorageVectors::urls()->variantPath(StorageVectors::blob($avatar['blob']), 'square'));
    }

    public function testVerifierVectors(): void
    {
        $vectors = StorageVectors::get('verifier');
        $disk = StorageVectors::disk();
        self::assertSame($vectors['expiring'], $disk->verifier->generate('x', 'p', new \DateTimeImmutable('2030-01-02T03:04:05.678Z')));
        $weird = new Filename('weird & <name> ünï.png');
        self::assertSame($vectors['disk_url_path'], $disk->urlPath('abcdefghijklmnopqrstuvwxyz12', null, $weird, 'image/png', 'inline'));
        self::assertSame($vectors['disk_url_path_nil_type'], $disk->urlPath('abcdefghijklmnopqrstuvwxyz12', null, $weird, null, 'attachment'));

        $token = explode('/', $vectors['direct_upload_path'])[4];
        $data = $disk->decodeToken($token, new \DateTimeImmutable('2026-09-26T12:45:00Z'));
        self::assertNotNull($data);
        $expiresAt = new \DateTimeImmutable('2026-09-26T12:45:14.841Z');
        self::assertSame($vectors['direct_upload_path'], $disk->directUploadPath('abcdefghijklmnopqrstuvwxyz12', $expiresAt, 'image/png', 42, 'abc=='));
        self::assertNull($disk->decodeToken($token, new \DateTimeImmutable('2026-09-26T12:46:00Z')), 'expired');

        $key = explode('/', $vectors['disk_url_path'])[4];
        self::assertSame('abcdefghijklmnopqrstuvwxyz12', $disk->decodeKey($key, Vectors::now())['key'] ?? null);
    }

    public function testDiskLayout(): void
    {
        self::assertSame('/root/1y/kr/1ykrknnn9r4youv0f71wrntkzilx', StorageVectors::disk('/root')->pathFor('1ykrknnn9r4youv0f71wrntkzilx'));
    }
}
