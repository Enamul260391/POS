<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Exact fixed-point arithmetic for monetary amounts (`decimal(15, 2)` columns).
 *
 * A POS must never lose a cent to binary floating point (`0.1 + 0.2 !== 0.3`
 * in PHP floats), so every money value travels as a decimal string and is
 * computed through bcmath (arbitrary-precision decimal arithmetic). Results
 * are rounded half-up to the two fractional digits the schema stores — the
 * rounding convention cashiers expect.
 *
 * Quantities (`decimal(15, 4)`) are deliberately out of scope: they keep
 * using {@see Quantity}, which owns the ledger's four-digit scale.
 *
 * Usage:
 *   Money::format(Money::multiply('1.5', '33.33'));  // "50.00"
 *   Money::subtract('526.25', '26.25');              // "500.00"
 */
final class Money
{
    /** Fractional digits stored by the money columns (`decimal(15, 2)`). */
    public const SCALE = 2;

    /** Largest value a `decimal(15, 2)` column can hold. */
    public const MAX = '9999999999999.99';

    /**
     * Normalise any monetary input to a two-decimal string (round half-up).
     *
     * Floats are rendered in fixed-point notation first so exponent notation
     * (`1.0E-5`) can never reach bcmath or the database.
     */
    public static function format(string|int|float|null $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $text = is_float($value) ? sprintf('%.10F', $value) : trim((string) $value);

        if ($text === '' || $text === '-' || $text === '+') {
            return '0.00';
        }

        return self::round($text);
    }

    /**
     * Add two amounts (`a + b`), rounded to the storage scale.
     */
    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::round(bcadd(self::format($a), self::format($b), 10));
    }

    /**
     * Subtract one amount from another (`a - b`), rounded to the storage scale.
     */
    public static function subtract(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::round(bcsub(self::format($a), self::format($b), 10));
    }

    /**
     * Multiply two amounts (`a × b`), rounded to the storage scale.
     *
     * The intermediate product is computed at ten fractional digits, which
     * covers the worst case (a four-digit quantity times a two-digit price)
     * exactly before the single final rounding to two digits.
     */
    public static function multiply(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::round(bcmul(self::format($a), self::format($b), 10));
    }

    /**
     * Whether two amounts are equal at the storage scale.
     */
    public static function equals(string|int|float|null $a, string|int|float|null $b): bool
    {
        return bccomp(self::format($a), self::format($b), self::SCALE) === 0;
    }

    /**
     * Whether `a` is strictly greater than `b` at the storage scale.
     */
    public static function greaterThan(string|int|float|null $a, string|int|float|null $b): bool
    {
        return bccomp(self::format($a), self::format($b), self::SCALE) > 0;
    }

    /**
     * Whether the amount is below zero.
     */
    public static function isNegative(string|int|float|null $value): bool
    {
        return bccomp(self::format($value), '0', self::SCALE) < 0;
    }

    /**
     * Whether the amount is exactly zero.
     */
    public static function isZero(string|int|float|null $value): bool
    {
        return bccomp(self::format($value), '0', self::SCALE) === 0;
    }

    /**
     * Round a decimal string half-up (away from zero) to {@see self::SCALE}
     * fractional digits.
     *
     * bcmath only truncates at a given scale, so the classic technique is
     * used: shift the absolute value by half a unit of the last digit
     * (`0.005`), truncate to two digits, then re-attach the sign. The sign is
     * applied to the absolute value rather than the shifted number, which
     * makes the rounding "half away from zero" for both directions.
     */
    private static function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');

        if ($absolute === '') {
            $absolute = '0';
        }

        $shifted = bcadd($absolute, '0.005', 10);
        // bcadd with scale 2 truncates the extra fractional digits.
        $rounded = bcadd($shifted, '0', self::SCALE);

        return $negative && bccomp($rounded, '0', self::SCALE) !== 0
            ? '-'.$rounded
            : $rounded;
    }
}