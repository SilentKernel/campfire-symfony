<?php

declare(strict_types=1);

namespace App\Storage\Processing;

use App\Storage\InvalidVariation;
use App\Storage\ProcessingError;
use App\Storage\Variation;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;
use Jcupitt\Vips\Introspect;

/**
 * libvips through php-vips, making exactly the calls ruby-vips makes for Active Storage:
 *
 * - `ActiveStorage::Transformers::Vips` (image_processing 1.14): `source(file).loader(page: 0)
 *   .convert(format).apply(operations).call`, i.e. `new_from_file(path, page: 0)` (the option only
 *   reaches loaders that take it), `autorot`, then per `resize_to_limit`
 *   `thumbnail_image(w, height: h, size: :down, no_rotate: true).conv(SHARPEN_MASK,
 *   precision: :integer)`, saved with the saver's defaults to a file named for the format;
 * - `ActiveStorage::Analyzer::ImageAnalyzer::Vips`: `new_from_file(path, access: :sequential)`.
 *
 * Variants are byte-identical to the reference's when libvips is the same version (8.16.1 in both
 * Debian trixie images).
 */
final class Vips
{
    /** `Vips::MAX_COORD` */
    public const int MAX_COORD = 10_000_000;

    private static bool $initialized = false;

    /** @var array<string, bool> loader name => accepts `page` */
    private static array $acceptsPage = [];

    /**
     * Writes the variant of the image at `$input` to a new temporary file and returns its path.
     *
     * @throws InvalidVariation|ProcessingError
     */
    public static function transform(string $input, Variation $variation): string
    {
        $format = $variation->format();
        $operations = self::operations($variation);
        self::init();

        $output = self::tempfile('image_processing', '.'.$format);
        try {
            $image = self::loadForProcessing($input);
            foreach ($operations as [$width, $height]) {
                $image = $image->thumbnail_image($width, ['height' => $height, 'size' => 'down', 'no_rotate' => true]);
                $image = $image->conv(self::sharpenMask(), ['precision' => 'integer']);
            }
            $image->writeToFile($output);
        } catch (VipsException $e) {
            @unlink($output);
            throw new ProcessingError('libvips: '.trim($e->getMessage()), 0, $e);
        } catch (\Throwable $e) {
            @unlink($output);
            throw $e;
        }

        return $output;
    }

    /**
     * `ImageAnalyzer::Vips#metadata`: width and height, swapped for EXIF orientations that turn
     * the image by 90°; `[]` when libvips can't read the file.
     *
     * @return array{width?: int, height?: int}
     */
    public static function imageMetadata(string $path): array
    {
        try {
            self::init();
            $image = Image::newFromFile($path, ['access' => 'sequential']);
            $width = $image->width;
            $height = $image->height;
            $rotated = false;
            if (0 !== $image->getType('exif-ifd0-Orientation')) {
                try {
                    $rotated = 1 === preg_match('/Right-top|Left-bottom|Top-right|Bottom-left/', (string) $image->get('exif-ifd0-Orientation'));
                } catch (VipsException) {
                }
            }

            return $rotated ? ['width' => $height, 'height' => $width] : ['width' => $width, 'height' => $height];
        } catch (VipsException|ProcessingError) {
            return [];
        }
    }

    /** `vips_init` plus config/initializers/vips.rb: block untrusted loaders and openslide. */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        try {
            Config::version();
        } catch (\Throwable $e) {
            throw new ProcessingError('libvips is unavailable: '.$e->getMessage(), 0, $e);
        }
        $libraries = \PHP_OS_FAMILY === 'Darwin'
            ? ['libvips.42.dylib', '/opt/homebrew/lib/libvips.42.dylib', '/usr/local/lib/libvips.42.dylib']
            : ['libvips.so.42'];
        $blocked = false;
        foreach ($libraries as $library) {
            try {
                $vips = self::blockingFunctions($library);
            } catch (\FFI\Exception) {
                continue;
            }
            $vips->vips_block_untrusted_set(1);
            $vips->vips_operation_block_set('VipsForeignLoadOpenslide', 1);
            $blocked = true;
            break;
        }
        if (!$blocked) {
            throw new ProcessingError('libvips is unavailable: cannot block its untrusted loaders.');
        }
        self::$initialized = true;
    }

    /**
     * The two libvips functions php-vips doesn't bind, through FFI (whose methods are dynamic).
     *
     * @throws \FFI\Exception
     */
    private static function blockingFunctions(string $library): mixed
    {
        return \FFI::cdef('void vips_block_untrusted_set(int state); void vips_operation_block_set(const char *name, int state);', $library);
    }

    /**
     * `ImageProcessingTransformer#operations`: every transformation but `format`, blank arguments
     * skipped. Campfire only defines `resize_to_limit`.
     *
     * @return list<array{int, int}>
     *
     * @throws InvalidVariation
     */
    public static function operations(Variation $variation): array
    {
        $operations = [];
        foreach ($variation->transformations as $name => $argument) {
            if ('format' === $name) {
                continue;
            }
            if ('combine_options' === $name) {
                throw new InvalidVariation("Active Storage's ImageProcessing transformer doesn't support :combine_options");
            }
            if (self::blank($argument)) {
                continue;
            }
            if ('resize_to_limit' !== $name || !\is_array($argument) || !array_is_list($argument) || \count($argument) < 1 || \count($argument) > 2) {
                throw new InvalidVariation(\sprintf('Unsupported transformation %s.', $name));
            }
            [$width, $height] = [$argument[0] ?? null, $argument[1] ?? null];
            foreach ([$width, $height] as $dimension) {
                if (null !== $dimension && (!\is_int($dimension) || $dimension < 0 || $dimension > 0x7FFFFFFF)) {
                    throw new InvalidVariation('Invalid resize_to_limit argument.');
                }
            }
            if (null === $width && null === $height) {
                throw new InvalidVariation('either width or height must be specified');
            }
            $operations[] = [$width ?? self::MAX_COORD, $height ?? self::MAX_COORD];
        }

        return $operations;
    }

    /** `ImageProcessing::Vips::Processor.load_image(path, page: 0)`, then `autorot`. */
    private static function loadForProcessing(string $path): Image
    {
        $loader = Image::findLoad($path);
        if (null === $loader) {
            throw new ProcessingError(\sprintf('libvips: "%s" is not a known file format', basename($path)));
        }
        $options = self::loaderAcceptsPage($loader) ? ['page' => 0] : [];

        return Image::newFromFile($path, $options)->autorot();
    }

    /** `Utils.select_valid_loader_options`: `page` is kept only for loaders with that optional input. */
    private static function loaderAcceptsPage(string $loader): bool
    {
        return self::$acceptsPage[$loader] ??= \in_array('page', (new Introspect($loader))->optional_input, true);
    }

    /** `SHARPEN_MASK = new_from_array([[-1,-1,-1],[-1,32,-1],[-1,-1,-1]], 24)` */
    private static function sharpenMask(): Image
    {
        return Image::newFromArray([[-1, -1, -1], [-1, 32, -1], [-1, -1, -1]], 24);
    }

    private static function blank(mixed $value): bool
    {
        return null === $value || false === $value || [] === $value || (\is_string($value) && '' === trim($value));
    }

    public static function tempfile(string $prefix, string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if (false === $base) {
            throw new ProcessingError('Cannot create a temporary file.');
        }
        if ('' === $suffix) {
            return $base;
        }
        $path = $base.$suffix;
        if (!@rename($base, $path)) {
            @unlink($base);
            throw new ProcessingError('Cannot create a temporary file.');
        }

        return $path;
    }
}
