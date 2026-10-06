<?php

declare(strict_types=1);

namespace App\Storage\Processing;

use App\Storage\ProcessingError;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The analyzers in `config.active_storage.analyzers` order: ImageAnalyzer::Vips, VideoAnalyzer and
 * AudioAnalyzer (ImageMagick never accepts with the :vips processor), else NullAnalyzer.
 */
final class Analyzer
{
    /** How long ffprobe may run; Rails sets no limit. */
    public const int FFPROBE_TIMEOUT = 30;

    public const string IMAGE = 'image';
    public const string VIDEO = 'video';
    public const string AUDIO = 'audio';
    public const string NULL = 'null';

    public static function for(?string $contentType): string
    {
        $contentType ??= '';

        return match (true) {
            str_starts_with($contentType, 'image') => self::IMAGE,
            str_starts_with($contentType, 'video') => self::VIDEO,
            str_starts_with($contentType, 'audio') => self::AUDIO,
            default => self::NULL,
        };
    }

    /** `analyzer_class.analyze_later?`: only the NullAnalyzer runs inline. */
    public static function analyzeLater(string $analyzer): bool
    {
        return self::NULL !== $analyzer;
    }

    /**
     * `analyzer.metadata` for a local copy of the blob.
     *
     * @return array<string, mixed>
     */
    public static function metadata(string $analyzer, string $path): array
    {
        return match ($analyzer) {
            self::IMAGE => Vips::imageMetadata($path),
            self::VIDEO => self::videoMetadata(self::probe($path)),
            self::AUDIO => self::audioMetadata(self::probe($path)),
            default => [],
        };
    }

    /**
     * `ffprobe -print_format json -show_streams -show_format -v error <path>`; `[]` without ffprobe.
     *
     * @return array<string, mixed>
     */
    public static function probe(string $path): array
    {
        $process = new Process(['ffprobe', '-print_format', 'json', '-show_streams', '-show_format', '-v', 'error', $path]);
        $process->setTimeout(self::FFPROBE_TIMEOUT);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ProcessingError('ffprobe timed out', 0, $e);
        }
        if (127 === $process->getExitCode()) {
            return []; // Errno::ENOENT: "Skipping video analysis because ffprobe isn't installed"
        }
        $output = $process->getOutput();
        try {
            $probe = json_decode('' === $output ? 'null' : $output, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ProcessingError('ffprobe output: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($probe)) {
            throw new ProcessingError('ffprobe output is not an object');
        }

        return $probe;
    }

    /**
     * `VideoAnalyzer#metadata`: `{ width:, height:, duration:, angle:, display_aspect_ratio:, audio:,
     * video: }.compact`.
     *
     * @param array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    public static function videoMetadata(array $probe): array
    {
        $video = self::stream($probe, 'video');
        $audio = self::stream($probe, 'audio');

        $tags = \is_array($video['tags'] ?? null) ? $video['tags'] : [];
        $angle = null;
        if (isset($tags['rotate'])) {
            $angle = self::rubyInteger($tags['rotate']);
        } else {
            $sideData = \is_array($video['side_data_list'] ?? null) ? $video['side_data_list'] : [];
            foreach ($sideData as $data) {
                if (\is_array($data) && 'Display Matrix' === ($data['side_data_type'] ?? null)) {
                    if (isset($data['rotation'])) {
                        $angle = self::rubyInteger($data['rotation']);
                    }
                    break;
                }
            }
        }

        $displayAspectRatio = null;
        if (isset($video['display_aspect_ratio'])) {
            $terms = explode(':', (string) $video['display_aspect_ratio'], 2);
            $numerator = self::rubyInteger($terms[0]);
            $denominator = self::rubyInteger($terms[1] ?? null);
            if (0 !== $numerator) {
                $displayAspectRatio = [$numerator, $denominator];
            }
        }

        $encodedWidth = isset($video['width']) ? self::rubyFloat($video['width']) : null;
        $encodedHeight = isset($video['height']) ? self::rubyFloat($video['height']) : null;
        $displayHeightScale = null === $displayAspectRatio ? null : (float) $displayAspectRatio[1] / $displayAspectRatio[0];
        $computedHeight = null !== $encodedWidth && null !== $displayHeightScale ? $encodedWidth * $displayHeightScale : null;
        $rotated = \in_array($angle, [90, 270, -90, -270], true);
        [$width, $height] = $rotated
            ? [$computedHeight ?? $encodedHeight, $encodedWidth]
            : [$encodedWidth, $computedHeight ?? $encodedHeight];

        $duration = $video['duration'] ?? (\is_array($probe['format'] ?? null) ? ($probe['format']['duration'] ?? null) : null);

        return array_filter([
            'width' => $width,
            'height' => $height,
            'duration' => null === $duration ? null : self::rubyFloat($duration),
            'angle' => $angle,
            'display_aspect_ratio' => $displayAspectRatio,
            'audio' => [] !== $audio,
            'video' => [] !== $video,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * `AudioAnalyzer#metadata`: `{ duration:, bit_rate:, sample_rate:, tags: }.compact`.
     *
     * @param array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    public static function audioMetadata(array $probe): array
    {
        $audio = self::stream($probe, 'audio');

        return array_filter([
            'duration' => isset($audio['duration']) ? self::rubyFloat($audio['duration']) : null,
            'bit_rate' => isset($audio['bit_rate']) ? self::rubyInteger($audio['bit_rate']) : null,
            'sample_rate' => isset($audio['sample_rate']) ? self::rubyInteger($audio['sample_rate']) : null,
            'tags' => isset($audio['tags']) && \is_array($audio['tags']) ? ([] === $audio['tags'] ? new \stdClass() : $audio['tags']) : null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    private static function stream(array $probe, string $codecType): array
    {
        foreach (\is_array($probe['streams'] ?? null) ? $probe['streams'] : [] as $stream) {
            if (\is_array($stream) && $codecType === ($stream['codec_type'] ?? null)) {
                return $stream;
            }
        }

        return [];
    }

    /** Ruby's `Float(value)` for the numbers and numeric strings ffprobe prints. */
    private static function rubyFloat(mixed $value): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw new ProcessingError(\sprintf('invalid value for Float(): %s', json_encode($value)));
    }

    /** Ruby's `Integer(value)`. */
    private static function rubyInteger(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (\is_string($value) && 1 === preg_match('/\A\s*[+-]?\d+(?:_\d+)*\s*\z/', $value)) {
            return (int) str_replace('_', '', trim($value));
        }

        throw new ProcessingError(\sprintf('invalid value for Integer(): %s', json_encode($value)));
    }
}
