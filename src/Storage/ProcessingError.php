<?php

declare(strict_types=1);

namespace App\Storage;

/** A failure in libvips, ffmpeg or ffprobe (Vips::Error, ActiveStorage::PreviewError). */
final class ProcessingError extends \RuntimeException
{
}
