<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Sale\InvoiceNumberService;

/**
 * The invoice number format, in one place.
 *
 * A sale's invoice number is the document the customer keeps, so the shape is
 * fixed and human readable:
 *
 *     {invoice_prefix}-{zero padded sequence}
 *
 *     INV-000001
 *     INV-000002
 *
 * The **prefix** is a per-business setting (`business_settings.invoice_prefix`),
 * so two businesses on the same deployment print their own branding. When the
 * setting is absent, blank or unusable it falls back to {@see self::DEFAULT_PREFIX}.
 *
 * The **sequence** is a per-business counter owned by
 * {@see InvoiceNumberService}; it is never derived from the
 * sale's own primary key and never computed with `MAX(...) + 1`, both of which
 * collide under concurrent checkouts.
 *
 * The padded width is a floor, not a ceiling: the 6th figure is only reached at
 * 999,999 invoices for one business, and past that the number simply grows
 * rather than wrapping or repeating.
 */
final class InvoiceNumber
{
    /** Used when the business has no usable `invoice_prefix` setting. */
    public const DEFAULT_PREFIX = 'INV';

    /** Minimum width of the numeric part; `1` renders as `000001`. */
    public const SEQUENCE_WIDTH = 6;

    /**
     * Longest prefix this class will emit.
     *
     * Bounded well under the 50 characters `sales.invoice_number` holds, so the
     * separator and a sequence that outgrows {@see self::SEQUENCE_WIDTH} can
     * never overflow the column.
     */
    public const MAX_PREFIX_LENGTH = 30;

    /**
     * Normalise a stored `invoice_prefix` into something safe to print.
     *
     * The value is free text typed by an operator, so it is upper-cased and
     * stripped of everything that is not a letter or a digit (spaces, dashes,
     * slashes, punctuation). An empty result means "no usable prefix" and the
     * documented default is used instead — a sale must never be numbered with a
     * bare `-000001`.
     */
    public static function sanitizePrefix(?string $raw): string
    {
        $prefix = strtoupper(trim((string) $raw));
        $prefix = preg_replace('/[^A-Z0-9]+/', '', $prefix) ?? '';

        if ($prefix === '') {
            return self::DEFAULT_PREFIX;
        }

        return substr($prefix, 0, self::MAX_PREFIX_LENGTH);
    }

    /**
     * Render one invoice number.
     *
     * `format('INV', 1) === 'INV-000001'`
     */
    public static function format(string $prefix, int $sequence): string
    {
        return self::sanitizePrefix($prefix).'-'.str_pad(
            (string) $sequence,
            self::SEQUENCE_WIDTH,
            '0',
            STR_PAD_LEFT,
        );
    }
}
