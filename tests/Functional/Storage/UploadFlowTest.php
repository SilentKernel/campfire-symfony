<?php

declare(strict_types=1);

namespace App\Tests\Functional\Storage;

use App\Entity\ActiveStorage\Attachment;
use App\Storage\Attachments;
use App\Storage\BlobService;
use App\Storage\Job\AnalyzeBlob;
use App\Storage\Job\PurgeBlob;
use App\Storage\MessageAttachmentProcessor;
use App\Storage\Processing\VideoPreviewer;
use App\Tests\Support\CampfireTestCase;
use App\Tests\Unit\Storage\StorageVectors;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Fixtures uploaded end to end through the services, as MessagesController#create does. */
final class UploadFlowTest extends CampfireTestCase
{
    private function attachToMessage(string $fixture, string $type): Attachment
    {
        if (!static::$booted) {
            $this->setEnv('CAMPFIRE_FROZEN_TIME', (string) self::labels('clock.now'));
            self::bootKernel();
        }
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true);
        copy(StorageVectors::fixture($fixture), $copy);
        $upload = new UploadedFile($copy, $fixture, $type, null, true);

        $blob = static::getContainer()->get(BlobService::class)->createFromUpload($upload);
        @unlink($copy);

        return static::getContainer()->get(Attachments::class)->attach('Message', self::id('messages.plain'), 'attachment', $blob);
    }

    public function testAnImageIsStoredAnalyzedAndThumbnailed(): void
    {
        $attachment = $this->attachToMessage('moon.jpg', 'image/jpeg');
        $blob = $attachment->getBlob();
        $row = $this->connection()->fetchAssociative('SELECT * FROM active_storage_blobs WHERE id = ?', [$blob->getId()]);

        self::assertSame('moon.jpg', $row['filename']);
        self::assertSame('image/jpeg', $row['content_type']);
        self::assertSame('j65kKv2abaidraFRfGAQlg==', $row['checksum']);
        self::assertSame(12794, (int) $row['byte_size']);
        self::assertSame('local', $row['service_name']);
        self::assertSame('{"identified":true}', $row['metadata']);
        self::assertMatchesRegularExpression('/\A[0-9a-z]{28}\z/', $row['key']);
        self::assertSame('2026-03-02 16:00:00', $row['created_at']);
        self::assertFileEquals(StorageVectors::fixture('moon.jpg'), $this->storagePath.'/files/'.$blob->getRelativePath());

        // after_create_commit :analyze_blob_later, and the message (then its room) is touched.
        self::assertContains($blob->getId(), $this->sentJobIds(AnalyzeBlob::class));
        self::assertSame('2026-03-02 16:00:00', $this->connection()->fetchOne('SELECT updated_at FROM messages WHERE id = ?', [self::id('messages.plain')]));

        static::getContainer()->get(MessageAttachmentProcessor::class)->process($attachment);

        self::assertSame('{"identified":true,"width":640,"height":640,"analyzed":true}', $this->connection()->fetchOne('SELECT metadata FROM active_storage_blobs WHERE id = ?', [$blob->getId()]));
        $variant = $this->connection()->fetchAssociative('SELECT * FROM active_storage_variant_records WHERE blob_id = ?', [$blob->getId()]);
        self::assertSame('IBhrLAIapu+NCId+2Kz6EqUWRKY=', $variant['variation_digest']);
        $image = $this->connection()->fetchAssociative("SELECT b.* FROM active_storage_blobs b JOIN active_storage_attachments a ON a.blob_id = b.id WHERE a.record_type = 'ActiveStorage::VariantRecord' AND a.record_id = ? AND a.name = 'image'", [$variant['id']]);
        self::assertSame('moon.jpg', $image['filename']);
        self::assertSame('image/jpeg', $image['content_type']);
        self::assertSame('{"identified":true}', $image['metadata']);
    }

    public function testAVideoGetsAPreviewImageAndAWebpVariant(): void
    {
        if (!VideoPreviewer::ffmpegExists()) {
            self::markTestSkipped('ffmpeg is not installed');
        }
        $attachment = $this->attachToMessage('alpha-centuri.mov', 'video/quicktime');
        static::getContainer()->get(MessageAttachmentProcessor::class)->process($attachment);
        $blob = $attachment->getBlob();

        self::assertSame('{"identified":true,"width":320.0,"height":180.0,"duration":65.84,"display_aspect_ratio":[16,9],"audio":false,"video":true,"analyzed":true}', $this->connection()->fetchOne('SELECT metadata FROM active_storage_blobs WHERE id = ?', [$blob->getId()]));
        $preview = $this->connection()->fetchAssociative("SELECT b.* FROM active_storage_blobs b JOIN active_storage_attachments a ON a.blob_id = b.id WHERE a.record_type = 'ActiveStorage::Blob' AND a.record_id = ? AND a.name = 'preview_image'", [$blob->getId()]);
        self::assertSame('alpha-centuri.jpg', $preview['filename']);
        self::assertSame('image/jpeg', $preview['content_type']);
        self::assertSame('IrCln/Mml8kDmpMni9j4/WNB7Uo=', $this->connection()->fetchOne('SELECT variation_digest FROM active_storage_variant_records WHERE blob_id = ?', [$preview['id']]));
    }

    public function testOtherFilesAreOnlyAnalyzed(): void
    {
        $attachment = $this->attachToMessage('pixel.bmp', 'image/bmp');
        static::getContainer()->get(MessageAttachmentProcessor::class)->process($attachment);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_variant_records WHERE blob_id = ?', [$attachment->getBlob()->getId()]));
    }

    public function testReplacingAndBatchLoadingAttachments(): void
    {
        $first = $this->attachToMessage('moon.jpg', 'image/jpeg');
        $second = $this->attachToMessage('pixel.bmp', 'image/bmp');
        $attachments = static::getContainer()->get(Attachments::class);

        $byMessage = $attachments->forRecords('Message', [self::id('messages.plain'), self::id('messages.image'), self::id('messages.video')], 'attachment');
        self::assertSame($second->getBlob()->getId(), $byMessage[self::id('messages.plain')]->getBlob()->getId());
        self::assertSame('moon.jpg', $byMessage[self::id('messages.image')]->getBlob()->getFilename());
        self::assertSame('alpha-centuri.mov', $byMessage[self::id('messages.video')]->getBlob()->getFilename());
        self::assertContains($first->getBlob()->getId(), $this->sentJobIds(PurgeBlob::class), 'the replaced blob is purged later');
    }

    public function testPurgeRemovesTheBlobItsVariantsAndFiles(): void
    {
        $attachment = $this->attachToMessage('moon.jpg', 'image/jpeg');
        static::getContainer()->get(MessageAttachmentProcessor::class)->process($attachment);
        $blob = $attachment->getBlob();
        $path = $this->storagePath.'/files/'.$blob->getRelativePath();
        $blobs = static::getContainer()->get(BlobService::class);

        $blobs->purge($blob);
        self::assertFileExists($path, 'an attached blob is kept');

        static::getContainer()->get(Attachments::class)->purge('Message', self::id('messages.plain'), 'attachment');
        self::assertFileDoesNotExist($path);
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM active_storage_blobs WHERE id = ?', [$blob->getId()]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_variant_records WHERE blob_id = ?', [$blob->getId()]));
    }

    /** @return list<int> */
    private function sentJobIds(string $class): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof $class) {
                $ids[] = $message->blobId;
            }
        }

        return $ids;
    }
}
