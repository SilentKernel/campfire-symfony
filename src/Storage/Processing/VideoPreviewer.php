<?php

declare(strict_types=1);

namespace App\Storage\Processing;

use App\Storage\ContentTypes;
use App\Storage\ProcessingError;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * ActiveStorage::Previewer::VideoPreviewer: `ffmpeg -i <input> <video_preview_arguments> -`,
 * capturing the JPEG frame from stdout. The PDF previewers never accept in Campfire's image (no
 * poppler or mupdf), so videos are the only previewable blobs.
 */
final class VideoPreviewer
{
    /** How long ffmpeg may take to draw a frame before it's killed. Rails sets no limit. */
    public const int FFMPEG_TIMEOUT = 60;

    private static ?bool $ffmpegExists = null;

    /** `VideoPreviewer.ffmpeg_exists?`: `system(ffmpeg, "-version")`, memoized. */
    public static function ffmpegExists(): bool
    {
        if (null === self::$ffmpegExists) {
            try {
                self::$ffmpegExists = 0 === (new Process(['ffmpeg', '-version']))->run();
            } catch (\Throwable) {
                self::$ffmpegExists = false;
            }
        }

        return self::$ffmpegExists;
    }

    /** `draw_relevant_frame_from(file)`: the frame's bytes. */
    public static function drawFrame(string $input): string
    {
        $process = new Process(['ffmpeg', '-i', $input, ...ContentTypes::VIDEO_PREVIEW_ARGUMENTS, '-']);
        $process->setTimeout(self::FFMPEG_TIMEOUT);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ProcessingError('ffmpeg timed out', 0, $e);
        }
        if (!$process->isSuccessful()) {
            throw new ProcessingError(\sprintf('ffmpeg failed (status %s): %s', $process->getExitCode() ?? 'nil', rtrim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }
}
