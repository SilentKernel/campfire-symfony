<?php

declare(strict_types=1);

namespace App\Tests\Functional\Storage;

use App\Tests\Support\CampfireTestCase;
use App\Twig\StorageExtension;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * message_attachment_presentation against the reference's markup for the seed's attachments
 * (GET /rooms/654632876/@933434500 on campfire-reference:app with the same seed; asset digests
 * normalized).
 */
final class AttachmentPresentationTest extends CampfireTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function presentations(): iterable
    {
        yield 'image' => ['messages.image', '<div class="max-inline-size center flex overflow-clip" style="width: 320px; aspect-ratio: 1.0;"><a class="flex" data-lightbox-target="image" data-action="lightbox#open" data-lightbox-url-value="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad/moon.jpg?disposition=attachment" href="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad/moon.jpg"><img width="640" height="640" class="message__attachment" loading="lazy" src="/rails/active_storage/representations/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad/eyJfcmFpbHMiOnsiZGF0YSI6eyJmb3JtYXQiOiJqcGciLCJyZXNpemVfdG9fbGltaXQiOlsxMjAwLDgwMF19LCJwdXIiOiJ2YXJpYXRpb24ifX0=--28426ca1e33b0fea71b8b10b7f52a844de5886cf/moon.jpg" /></a></div>'];
        yield 'large image' => ['messages.image_large', '<div class="max-inline-size center flex overflow-clip" style="width: 600.0px; aspect-ratio: 1.7777777777777777;"><a class="flex" data-lightbox-target="image" data-action="lightbox#open" data-lightbox-url-value="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NywicHVyIjoiYmxvYl9pZCJ9fQ==--5c78c39b893e0551bfd62ea8510c19582cc61b35/black_hole.jpg?disposition=attachment" href="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NywicHVyIjoiYmxvYl9pZCJ9fQ==--5c78c39b893e0551bfd62ea8510c19582cc61b35/black_hole.jpg"><img width="1200.0" height="675.0" class="message__attachment" loading="lazy" src="/rails/active_storage/representations/redirect/eyJfcmFpbHMiOnsiZGF0YSI6NywicHVyIjoiYmxvYl9pZCJ9fQ==--5c78c39b893e0551bfd62ea8510c19582cc61b35/eyJfcmFpbHMiOnsiZGF0YSI6eyJmb3JtYXQiOiJqcGciLCJyZXNpemVfdG9fbGltaXQiOlsxMjAwLDgwMF19LCJwdXIiOiJ2YXJpYXRpb24ifX0=--28426ca1e33b0fea71b8b10b7f52a844de5886cf/black_hole.jpg" /></a></div>'];
        yield 'video' => ['messages.video', '<div class="max-inline-size center flex overflow-clip" style="width: 160.0px; aspect-ratio: 1.7777777777777777;"><video src="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6OSwicHVyIjoiYmxvYl9pZCJ9fQ==--c41e212f6aa839f5dc122c08987d3d9c13a372a0/alpha-centuri.mov" poster="/rails/active_storage/representations/redirect/eyJfcmFpbHMiOnsiZGF0YSI6OSwicHVyIjoiYmxvYl9pZCJ9fQ==--c41e212f6aa839f5dc122c08987d3d9c13a372a0/eyJfcmFpbHMiOnsiZGF0YSI6eyJmb3JtYXQiOiJ3ZWJwIiwicmVzaXplX3RvX2xpbWl0IjpbMTIwMCw4MDBdfSwicHVyIjoidmFyaWF0aW9uIn19--132e54230de6ddc9c41e0118730416f2e3d3127f/alpha-centuri.mov" controls="controls" preload="none" width="100%" height="100%" class="message__attachment"></video></div>'];
        yield 'file' => ['messages.file', '<div class="flex-inline align-center gap-half"><img class="colorize--black" aria-hidden="true" src="/assets/common-file-text-DIGEST.svg" width="22" height="22" /><span>launch-notes.txt</span><a class="btn message__action-btn hide-in-ios-pwa" style="--width: auto;" href="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6MTMsInB1ciI6ImJsb2JfaWQifX0=--ec01704dc07d2fc78abbfa05a6bc0ed07efeccd9/launch-notes.txt?disposition=attachment"><img aria-hidden="true" src="/assets/download-DIGEST.svg" width="20" height="20" /><span class="for-screen-reader">Download launch-notes.txt</span></a><button class="btn message__action-btn" style="--width: auto;" data-controller="web-share" data-action="web-share#share" data-web-share-files-value="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6MTMsInB1ciI6ImJsb2JfaWQifX0=--ec01704dc07d2fc78abbfa05a6bc0ed07efeccd9/launch-notes.txt?disposition=attachment"><img aria-hidden="true" src="/assets/share-DIGEST.svg" width="20" height="20" /><span class="for-screen-reader">Share launch-notes.txt</span></button></div>'];
        yield 'bmp' => ['messages.file_bmp', '<div class="flex-inline align-center gap-half"><img class="colorize--black" aria-hidden="true" src="/assets/common-file-text-DIGEST.svg" width="22" height="22" /><span>pixel.bmp</span><a class="btn message__action-btn hide-in-ios-pwa" style="--width: auto;" href="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6MTQsInB1ciI6ImJsb2JfaWQifX0=--c633a89b8189242ccbcaa2c36cca6348768e101a/pixel.bmp?disposition=attachment"><img aria-hidden="true" src="/assets/download-DIGEST.svg" width="20" height="20" /><span class="for-screen-reader">Download pixel.bmp</span></a><button class="btn message__action-btn" style="--width: auto;" data-controller="web-share" data-action="web-share#share" data-web-share-files-value="/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6MTQsInB1ciI6ImJsb2JfaWQifX0=--c633a89b8189242ccbcaa2c36cca6348768e101a/pixel.bmp?disposition=attachment"><img aria-hidden="true" src="/assets/share-DIGEST.svg" width="20" height="20" /><span class="for-screen-reader">Share pixel.bmp</span></button></div>'];
        yield 'no attachment' => ['messages.plain', ''];
    }

    #[DataProvider('presentations')]
    public function testRendersLikeTheReference(string $message, string $expected): void
    {
        if ('messages.video' === $message && !\App\Storage\Processing\VideoPreviewer::ffmpegExists()) {
            self::markTestSkipped('ffmpeg is not installed');
        }
        self::bootKernel();
        $html = static::getContainer()->get(StorageExtension::class)->messageAttachmentPresentation(self::id($message));
        self::assertSame($expected, preg_replace('#(/assets/[a-z-]+?)-[A-Za-z0-9_-]{7,8}\.svg#', '$1-DIGEST.svg', $html));
    }

    public function testTheTwigFunctions(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        $template = $twig->createTemplate("{{ message_attachment_presentation(id) }}|{{ rails_blob_path(attachment, 'attachment') }}");
        $attachment = static::getContainer()->get(\App\Storage\Attachments::class)->find('Message', self::id('messages.file'), 'attachment');
        $html = $template->render(['id' => self::id('messages.file'), 'attachment' => $attachment]);
        self::assertStringStartsWith('<div class="flex-inline align-center gap-half">', $html);
        self::assertStringEndsWith('|/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsiZGF0YSI6MTMsInB1ciI6ImJsb2JfaWQifX0=--ec01704dc07d2fc78abbfa05a6bc0ed07efeccd9/launch-notes.txt?disposition=attachment', $html);
    }
}
