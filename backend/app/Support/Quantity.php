<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Exact fixed-point arithmetic for inventory quantities.
 *
 * `stock_movements.quantity` is a `decimal(15, 4)` column, so every ledger
 * number carries at most four fractional digits. PHP floats cannot represent
 * those values exactly (`0.1 + 0.2 !== 0.3`) and a POS must never drift by a
 * rounding error, so quantities are converted to scaled integers
 * (value × 10 000), arithmetic happens on those integers, and the result is
 * formatted back into a decimal string.
 *
 * Monetary values are deliberately out of scope: money keeps its Eloquent
 * `decimal:2` cast and is never recalculated in PHP.
 *
 * Usage:
 *   Quantity::format(Quantity::subtract('7.5', '2.25'));  // "5.25"
 *   Quantity::isNegative(Quantity::subtract('3', '5'));   // true
 */
final class Quantity
{
    /** Fractional digits stored by the ledger (`decimal(15, 4)`). */
    public const SCALE = 4;

    /** Largest value the `decimal(15, 4)` column can hold. */
    public const MAX = '99999999999.9999';

    /** Multiplier between a scaled integer and whole units. */
    private const FACTOR = 10_000;

    /**
     * Multiply a quantity by 10 000 and return it as an integer.
     *
     * Only the first {@see self::SCALE} fractional digits are kept; callers
     * validate the scale of user input before it reaches the ledger.
     */
    public static function toScaled(string|int|float|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        // Floats are rendered without exponent notation and rounded to the
        // ledger scale before being parsed, so 0.1 + 0.2 style artefacts
        // cannot reach the database.
        $text = is_float($value) ? sprintf('%.'.self::SCALE.'F', $value) : trim((string) $value);

        if ($text === '' || $text === '-' || $text === '+') {
            return 0;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = substr(
            str_pad((string) preg_replace('/\D/', '', $fraction), self::SCALE, '0'),
            0,
            self::SCALE,
        );

        $scaled = self::wholeToInt($whole) * self::FACTOR + (int) $fraction;

        return $negative ? -$scaled : $scaled;
    }

    /**
     * Normalise any quantity to a decimal string with at most
     * {@see self::SCALE} fractional digits and no trailing zeros.
     */
    public static function format(string|int|float|null $value): string
    {
        return self::fromScaled(self::toScaled($value));
    }

    /**
     * Subtract one quantity from another (`a - b`).
     */
    public static function subtract(
        string|int|float|null $a,
        string|int|float|null $b,
    ): string {
        return self::fromScaled(self::toScaled($a) - self::toScaled($b));
    }

    /**
     * Add two quantities (`a + b`).
     */
    public static function add(
        string|int|float|null $a,
        string|int|float|null $b,
    ): string {
        return self::fromScaled(self::toScaled($a) + self::toScaled($b));
    }

    /**
     * Flip the sign of a quantity.
     */
    public static function negate(string|int|float|null $value): string
    {
        return self::fromScaled(-self::toScaled($value));
    }

    /**
     * Whether the quantity is below zero.
     */
    public static function isNegative(string|int|float|null $value): bool
    {
        return self::toScaled($value) < 0;
    }

    /**
     * Whether the quantity is exactly zero.
     */
    public static function isZero(string|int|float|null $value): bool
    {
        return self::toScaled($value) === 0;
    }

    /**
     * Whether the quantity is above zero.
     */
    public static function isPositive(string|int|float|null $value): bool
    {
        return self::toScaled($value) > 0;
    }

    /**
     * Format a scaled integer back into a decimal string.
     */
    private static function fromScaled(int $scaled): string
    {
        $negative = $scaled < 0;
        $absolute = $negative ? -$scaled : $scaled;
        $whole = intdiv($absolute, self::FACTOR);
        $fraction = $absolute % self::FACTOR;

        $formatted = $fraction === 0
            ? (string) $whole
            : $whole.'.'.rtrim(str_pad((string) $fraction, self::SCALE, '0', STR_PAD_LEFT), '0');

        return $negative && $formatted !== '0' ? '-'.$formatted : $formatted;
    }

    /**
     * Digits-only conversion of the integer part of a decimal string.
     */
    private static function wholeToInt(string $whole): int
    {
        $digits = (string) preg_replace('/\D/', '', $whole);

        return $digits === '' ? 0 : (int) $digits;
    }
}
