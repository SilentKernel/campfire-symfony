<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * The two ways Ruby writes a Float: Float#to_s (numeric.c flo_to_s) and the json gem's generator
 * (json 2.21.2 ext/json/ext/vendor/fpconv.c), which ActiveSupport::JSON uses. Both start from the
 * shortest digit string that round-trips.
 */
final class RubyFloat
{
    /** Float#to_s: "1.0", "0.0001", "1.0e-05", "1.0e+16", "Infinity", "NaN". */
    public static function toS(float $float): string
    {
        if (is_nan($float)) {
            return 'NaN';
        }
        if (is_infinite($float)) {
            return $float > 0 ? 'Infinity' : '-Infinity';
        }
        if (0.0 === $float) {
            return (fdiv(1, $float) < 0 ? '-' : '').'0.0';
        }
        $sign = $float < 0 ? '-' : '';
        [$digits, $exponent] = self::shortest(abs($float));
        $decpt = $exponent + 1;
        $count = \strlen($digits);

        if ($decpt > 0 && $decpt < $count) {
            $body = substr($digits, 0, $decpt).'.'.substr($digits, $decpt);
        } elseif ($decpt > 0 && $decpt <= 15) {
            $body = $digits.str_repeat('0', $decpt - $count).'.0';
        } elseif ($decpt <= 0 && $decpt > -4) {
            $body = '0.'.str_repeat('0', -$decpt).$digits;
        } else {
            $fraction = $count > 1 ? substr($digits, 1) : '0';
            $body = $digits[0].'.'.$fraction.'e'.\sprintf('%+03d', $decpt - 1);
        }

        return $sign.$body;
    }

    /**
     * A finite float as the json gem writes it: "320.0", "0.00001", "1e+15", "1e-10". Non-finite
     * floats are null (ActiveSupport's Float#as_json).
     */
    public static function toJson(float $float): string
    {
        if (!is_finite($float)) {
            return 'null';
        }
        if (0.0 === $float) {
            return (fdiv(1, $float) < 0 ? '-' : '').'0.0';
        }
        $sign = $float < 0 ? '-' : '';
        [$digits, $exponent] = self::shortest(abs($float));
        // fpconv's K: the power of ten of the last digit.
        $k = $exponent + 1 - \strlen($digits);

        if ($k >= 0 && $exponent < 15) {
            $body = $digits.str_repeat('0', $k).'.0';
        } elseif ($k < 0 && ($k > -7 || abs($exponent) < 10)) {
            $point = $exponent + 1;
            $body = $point <= 0
                ? '0.'.str_repeat('0', -$point).$digits
                : substr($digits, 0, $point).'.'.substr($digits, $point);
        } else {
            $fraction = \strlen($digits) > 1 ? '.'.substr($digits, 1) : '';
            $body = $digits[0].$fraction.'e'.($exponent < 0 ? '-' : '+').abs($exponent);
        }

        return $sign.$body;
    }

    /**
     * Shortest round-tripping significant digits of a positive finite float, and the decimal
     * exponent of the first digit.
     *
     * @return array{string, int}
     */
    private static function shortest(float $float): array
    {
        for ($precision = 0; $precision < 17; ++$precision) {
            [$mantissa, $exponent] = explode('e', \sprintf('%.'.$precision.'e', $float));
            $digits = str_replace('.', '', $mantissa);
            // Correct rounding can land on a string that does not round-trip when the float sits
            // exactly between two decimals (2**-24); its neighbour in the last place may.
            foreach ([0, 1, -1] as $step) {
                [$candidate, $candidateExponent] = self::step($digits, (int) $exponent, $step);
                if ('0' !== $candidate[0] && (float) ($candidate[0].'.'.substr($candidate, 1).'e'.$candidateExponent) === $float) {
                    $trimmed = rtrim($candidate, '0');

                    return ['' === $trimmed ? '0' : $trimmed, $candidateExponent];
                }
            }
        }
        [$mantissa, $exponent] = explode('e', \sprintf('%.16e', $float));
        $trimmed = rtrim(str_replace('.', '', $mantissa), '0');

        return ['' === $trimmed ? '0' : $trimmed, (int) $exponent];
    }

    /** @return array{string, int} */
    private static function step(string $digits, int $exponent, int $step): array
    {
        if (0 === $step) {
            return [$digits, $exponent];
        }
        $stepped = (string) ((int) $digits + $step);
        if (\strlen($stepped) > \strlen($digits)) {
            return [substr($stepped, 0, -1), $exponent + 1];
        }

        return [str_pad($stepped, \strlen($digits), '0', \STR_PAD_LEFT), $exponent];
    }
}
