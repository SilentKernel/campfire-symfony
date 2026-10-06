<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Rails\RailsJson;
use App\Storage\BlobService;
use App\Storage\NamedVariants;
use App\Storage\Processing\Analyzer;
use App\Storage\Processing\VideoPreviewer;
use App\Storage\Processing\Vips;
use App\Storage\Variation;
use Jcupitt\Vips\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Variants and previews against the files the reference produced. Bytes match only with the
 * reference image's libvips (8.16.1) and ffmpeg (7.1.5), i.e. inside the dev image
 * (`bin/check phpunit`); elsewhere only the dimensions are compared.
 */
#[Group('media')]
final class MediaTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
    }

    /** @return iterable<string, array{string, string, string}> fixture, named variant, vector file */
    public static function variants(): iterable
    {
        foreach (StorageVectors::get('messages') as $message) {
            if (isset($message['thumb_path'])) {
                yield $message['variants'][0]['label'] => [$message['fixture'], 'thumb', $message['variants'][0]['file'], $message['blob']];
            }
        }
        foreach (StorageVectors::get('avatars') as $avatar) {
            yield $avatar['variants'][0]['label'] => [$avatar['fixture'], 'square', $avatar['variants'][0]['file'], $avatar['blob']];
        }
        foreach (StorageVectors::get('logos')[0]['variants'] as $variant) {
            yield $variant['label'] => ['black_hole.jpg', str_ends_with($variant['label'], 'large') ? 'large' : 'small', $variant['file'], StorageVectors::get('logos')[0]['blob']];
        }
    }

    /** @param array<string, mixed> $blobRow */
    #[DataProvider('variants')]
    public function testVariantsMatchTheReference(string $fixture, string $name, string $file, array $blobRow): void
    {
        $this->requireVips();
        $blob = StorageVectors::blob($blobRow);
        $variation = NamedVariants::get($name)->defaultTo(['format' => BlobService::defaultVariantFormat($blob)]);
        $output = $this->track(Vips::transform(StorageVectors::fixture($fixture), $variation));

        self::assertSame(Vips::imageMetadata(StorageVectors::file($file)), Vips::imageMetadata($output));
        if ($this->referenceLibvips()) {
            self::assertSame(md5_file(StorageVectors::file($file)), md5_file($output), $file.' differs from the reference');
        }
    }

    public function testImageAnalysisMatchesTheReference(): void
    {
        $this->requireVips();
        foreach (StorageVectors::get('messages') as $message) {
            if (!str_starts_with($message['blob']['content_type'], 'image')) {
                continue;
            }
            $metadata = BlobService::mergeMetadata('{"identified":true}', Analyzer::metadata(Analyzer::IMAGE, StorageVectors::fixture($message['fixture'])) + ['analyzed' => true]);
            self::assertSame($message['blob']['metadata'], $metadata, $message['fixture']);
        }
    }

    public function testVideoAnalysisAndPreviewMatchTheReference(): void
    {
        if (!VideoPreviewer::ffmpegExists()) {
            self::markTestSkipped('ffmpeg is not installed');
        }
        $video = StorageVectors::get('messages')[3];
        $movie = StorageVectors::fixture($video['fixture']);
        $metadata = BlobService::mergeMetadata('{"identified":true}', Analyzer::metadata(Analyzer::VIDEO, $movie) + ['analyzed' => true]);
        self::assertSame($video['blob']['metadata'], $metadata);

        // The vector's frame came from another ffmpeg build; the seed's preview image was drawn by
        // the reference image's own ffmpeg 7.1.5 (campfire-reference:app), like ours.
        $frame = VideoPreviewer::drawFrame($movie);
        $seedPreview = \dirname(__DIR__, 3).'/var/seed/default/storage/35/9w/359ws0k2x3iscwqju6x1z5fotuj9';
        if ($this->referenceFfmpeg() && is_file($seedPreview)) {
            self::assertSame(md5_file($seedPreview), md5($frame), 'the ffmpeg frame differs from the reference image\'s');
        }

        $this->requireVips();
        $preview = StorageVectors::file($video['preview_image']['file']);
        foreach ($video['variants'] as $variant) {
            $output = $this->track(Vips::transform($preview, new Variation(StorageVectors::typed($variant['transformations_typed']))->defaultTo(['format' => 'jpg'])));
            self::assertSame(Vips::imageMetadata(StorageVectors::file($variant['file'])), Vips::imageMetadata($output));
            if ($this->referenceLibvips()) {
                self::assertSame(md5_file(StorageVectors::file($variant['file'])), md5_file($output), $variant['file']);
            }
        }
    }

    public function testVideoPreviewArguments(): void
    {
        self::assertSame(StorageVectors::get('video_preview_arguments'), implode(' ', array_map(
            static fn (string $argument): string => str_contains($argument, '(') ? "'".$argument."'" : $argument,
            \App\Storage\ContentTypes::VIDEO_PREVIEW_ARGUMENTS,
        )));
    }

    public function testUnreadableImagesAnalyzeToNothing(): void
    {
        $this->requireVips();
        self::assertSame([], Vips::imageMetadata(StorageVectors::fixture('.keep')));
        // Vips.block_untrusted(true) (config/initializers/vips.rb): BMP needs the untrusted magick
        // loader, so the seed's pixel.bmp has no dimensions either.
        self::assertSame([], Vips::imageMetadata(StorageVectors::fixture('pixel.bmp')));
        self::assertSame('{"identified":true,"analyzed":true}', BlobService::mergeMetadata('{"identified":true}', ['analyzed' => true]));
        self::assertSame('{"analyzed":true}', RailsJson::encode(['analyzed' => true]));
    }

    private function track(string $path): string
    {
        $this->temporary[] = $path;

        return $path;
    }

    private function requireVips(): void
    {
        try {
            Vips::init();
        } catch (\Throwable $e) {
            self::markTestSkipped('libvips is unavailable: '.$e->getMessage());
        }
    }

    private function referenceLibvips(): bool
    {
        return StorageVectors::get('versions.libvips') === Config::version();
    }

    private function referenceFfmpeg(): bool
    {
        $process = new Process(['ffmpeg', '-version']);
        $process->run();

        return str_starts_with(StorageVectors::get('versions.ffmpeg'), strtok($process->getOutput(), "\n") ?: '?');
    }
}
