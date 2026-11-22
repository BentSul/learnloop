<?php

namespace App\Services\Adaptive\Calculation;

use InvalidArgumentException;

/**
 * Converts marks to integer hundredths so thresholds are compared exactly (spec §5.1).
 *
 * Marks are stored as DECIMAL(6,2). Laravel returns those as strings such as "7.99",
 * so the string form is parsed digit by digit instead of going through a float.
 */
final class Marks
{
    public static function toHundredths(int|float|string $marks): int
    {
        if (is_int($marks)) {
            return $marks * 100;
        }

        if (is_float($marks)) {
            return (int) round($marks * 100);
        }

        $value = trim($marks);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid mark value: '{$marks}'. Expected a number with at most two decimal places.");
        }

        $whole = (int) $m[2];
        $fraction = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;
        $hundredths = $whole * 100 + $fraction;

        return $m[1] === '-' ? -$hundredths : $hundredths;
    }

    /** Converts integer hundredths back to a number for API responses (JSON numbers, spec §13). */
    public static function fromHundredths(int $hundredths): float
    {
        return $hundredths / 100;
    }
}
