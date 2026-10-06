<?php

declare(strict_types=1);

namespace App\Domain\Users;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Common\Version;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Encoder\MaskUtil;
use BaconQrCode\Encoder\MatrixUtil;

/**
 * `RQRCode::QRCode.new(url).as_svg(viewbox: true, fill: :white, color: :black)`
 * (reference/app/controllers/qr_code_controller.rb, rqrcode 3 / rqrcode_core 2): level H, the
 * smallest version, byte mode without ECI, one `<rect>` per dark module of 11 units.
 *
 * bacon-qr-code encodes the data and places the modules; the mask is then chosen the way
 * rqrcode_core does (its own demerit points, computed with the format and version information
 * left light), since bacon's ZXing penalty rules can pick another one.
 */
final class QrCodeSvg
{
    private const int MODULE_SIZE = 11;

    public static function render(string $text): string
    {
        $modules = self::modules($text);
        $count = \count($modules);
        $size = $count * self::MODULE_SIZE;

        $svg = '<?xml version="1.0" standalone="yes"?>'
            .'<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:ev="http://www.w3.org/2001/xml-events" viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges">'
            .'<rect width="'.$size.'" height="'.$size.'" x="0" y="0" fill="white"/>';
        foreach ($modules as $row => $cells) {
            foreach ($cells as $col => $dark) {
                if ($dark) {
                    $svg .= '<rect width="11" height="11" x="'.($col * self::MODULE_SIZE).'" y="'.($row * self::MODULE_SIZE).'" fill="black"/>';
                }
            }
        }

        return $svg.'</svg>';
    }

    /**
     * The module matrix, `modules[row][col]`, as rqrcode_core builds it.
     *
     * @return list<list<bool>>
     */
    public static function modules(string $text): array
    {
        $ecLevel = ErrorCorrectionLevel::H();
        $qrCode = Encoder::encode($text, $ecLevel);
        $version = $qrCode->getVersion();
        $encodedMask = $qrCode->getMaskPattern();
        $matrix = $qrCode->getMatrix();
        $count = $matrix->getWidth();

        [$function, $formatCells] = self::functionPatterns($version, $ecLevel, $count);

        $best = 0;
        $minimum = 0.0;
        for ($mask = 0; $mask < 8; ++$mask) {
            $test = self::masked($matrix, $function, $encodedMask, $mask, $count, $formatCells);
            $points = self::lostPoints($test);
            if (0 === $mask || $minimum > $points) {
                $minimum = $points;
                $best = $mask;
            }
        }

        if ($best === $encodedMask) {
            $modules = [];
            for ($row = 0; $row < $count; ++$row) {
                for ($col = 0; $col < $count; ++$col) {
                    $modules[$row][$col] = 1 === $matrix->get($col, $row);
                }
            }

            return $modules;
        }

        // Rebuild the final matrix (real format/version information) with the chosen mask.
        $final = new ByteMatrix($count, $count);
        MatrixUtil::clearMatrix($final);
        self::call('embedBasicPatterns', $version, $final);
        self::call('embedTypeInfo', $ecLevel, $best, $final);
        self::call('maybeEmbedVersionInfo', $version, $final);
        $modules = [];
        for ($row = 0; $row < $count; ++$row) {
            for ($col = 0; $col < $count; ++$col) {
                $value = $final->get($col, $row);
                $modules[$row][$col] = -1 === $value
                    ? self::remask(1 === $matrix->get($col, $row), $encodedMask, $best, $col, $row)
                    : 1 === $value;
            }
        }

        return $modules;
    }

    /**
     * rqrcode_core's `make_impl(true, mask)`: format and version modules (and the dark module)
     * light, data modules re-masked.
     *
     * @param array<int, array<int, int>>  $function
     * @param array<int, array<int, true>> $formatCells
     *
     * @return list<list<bool>>
     */
    private static function masked(ByteMatrix $matrix, array $function, int $encodedMask, int $mask, int $count, array $formatCells): array
    {
        $modules = [];
        for ($row = 0; $row < $count; ++$row) {
            for ($col = 0; $col < $count; ++$col) {
                if (isset($formatCells[$row][$col])) {
                    $modules[$row][$col] = false;
                } elseif (-1 !== $function[$row][$col]) {
                    $modules[$row][$col] = 1 === $function[$row][$col];
                } else {
                    $modules[$row][$col] = self::remask(1 === $matrix->get($col, $row), $encodedMask, $mask, $col, $row);
                }
            }
        }

        return $modules;
    }

    /**
     * Function pattern cells (-1 for data) and the cells of the format/version information plus
     * the dark module, which rqrcode leaves light while choosing the mask.
     *
     * @return array{array<int, array<int, int>>, array<int, array<int, true>>}
     */
    private static function functionPatterns(Version $version, ErrorCorrectionLevel $ecLevel, int $count): array
    {
        $basic = new ByteMatrix($count, $count);
        MatrixUtil::clearMatrix($basic);
        self::call('embedBasicPatterns', $version, $basic);

        $full = new ByteMatrix($count, $count);
        MatrixUtil::clearMatrix($full);
        self::call('embedBasicPatterns', $version, $full);
        self::call('embedTypeInfo', $ecLevel, 0, $full);
        self::call('maybeEmbedVersionInfo', $version, $full);

        $function = [];
        $formatCells = [];
        for ($row = 0; $row < $count; ++$row) {
            for ($col = 0; $col < $count; ++$col) {
                $function[$row][$col] = $full->get($col, $row);
                if (-1 === $basic->get($col, $row) && -1 !== $full->get($col, $row)) {
                    $formatCells[$row][$col] = true;
                }
            }
        }
        // The dark module (`@modules[@module_count - 8][8] = !test`).
        $formatCells[$count - 8][8] = true;

        return [$function, $formatCells];
    }

    /** A data module encoded under $from, as it reads under $to. */
    private static function remask(bool $dark, int $from, int $to, int $col, int $row): bool
    {
        return ($dark xor MaskUtil::getDataMaskBit($from, $col, $row)) xor MaskUtil::getDataMaskBit($to, $col, $row);
    }

    private static function call(string $method, mixed ...$arguments): void
    {
        (new \ReflectionMethod(MatrixUtil::class, $method))->invoke(null, ...$arguments);
    }

    /**
     * `QRUtil.get_lost_points(modules)`.
     *
     * @param list<list<bool>> $modules
     */
    private static function lostPoints(array $modules): float
    {
        $count = \count($modules);
        $max = $count - 1;
        $points = 0;

        // Level 1: same-colored neighbours in the 3x3 square.
        for ($row = 0; $row < $count; ++$row) {
            for ($col = 0; $col < $count; ++$col) {
                $dark = $modules[$row][$col];
                $same = 0;
                for ($r = -1; $r <= 1; ++$r) {
                    if ($row + $r < 0 || $row + $r > $max) {
                        continue;
                    }
                    for ($c = -1; $c <= 1; ++$c) {
                        if ((0 === $r && 0 === $c) || $col + $c < 0 || $col + $c > $max) {
                            continue;
                        }
                        if ($dark === $modules[$row + $r][$col + $c]) {
                            ++$same;
                        }
                    }
                }
                if ($same > 5) {
                    $points += 3 + $same - 5;
                }
            }
        }

        // Level 2: 2x2 blocks.
        for ($row = 0; $row < $max; ++$row) {
            for ($col = 0; $col < $max; ++$col) {
                $value = $modules[$row][$col];
                if ($value === $modules[$row + 1][$col] && $value === $modules[$row][$col + 1] && $value === $modules[$row + 1][$col + 1]) {
                    $points += 3;
                }
            }
        }

        // Level 3: 1:1:3:1:1 patterns in rows and columns.
        $maxStart = $count - 7 + 1;
        for ($row = 0; $row < $count; ++$row) {
            for ($col = 0; $col < $maxStart; ++$col) {
                $m = $modules[$row];
                if ($m[$col] && !$m[$col + 1] && $m[$col + 2] && $m[$col + 3] && $m[$col + 4] && !$m[$col + 5] && $m[$col + 6]) {
                    $points += 40;
                }
            }
        }
        for ($col = 0; $col < $count; ++$col) {
            for ($row = 0; $row < $maxStart; ++$row) {
                if ($modules[$row][$col] && !$modules[$row + 1][$col] && $modules[$row + 2][$col] && $modules[$row + 3][$col] && $modules[$row + 4][$col] && !$modules[$row + 5][$col] && $modules[$row + 6][$col]) {
                    $points += 40;
                }
            }
        }

        // Level 4: dark ratio.
        $darkCount = 0;
        foreach ($modules as $cells) {
            $darkCount += \count(array_filter($cells));
        }
        $ratio = $darkCount / ($count * $count);

        return $points + abs(100 * $ratio - 50) / 5 * 10;
    }
}
