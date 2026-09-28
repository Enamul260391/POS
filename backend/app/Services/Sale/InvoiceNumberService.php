<?php

declare(strict_types=1);

namespace App\Services\Sale;

use App\Models\BusinessSetting;
use App\Models\InvoiceSequence;
use App\Support\InvoiceNumber;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The single authority that hands out sale invoice numbers.
 *
 * **Why a dedicated sequence table.** The number must be unique per business
 * and must survive concurrency, which rules out the two obvious shortcuts:
 *
 *   - `MAX(invoice_number) + 1` — two cashiers checking out at the same instant
 *     read the same maximum and persist the same number.
 *   - `COUNT(*) + 1` — a deleted draft or a rolled-back basket moves the count.
 *
 * Neither is atomic. Instead each business owns one `invoice_sequences` row
 * (unique index on `business_id`) holding the next number to hand out. The row
 * is read `lockForUpdate()`, incremented and saved **inside the caller's
 * transaction**, so the database serialises every allocation for that business
 * and two simultaneous sales can never observe the same value.
 *
 * **Rollback policy — numbers are reused after a failed sale.** Allocation is
 * part of the sale's own transaction, so a basket that fails validation, stock
 * or payment leaves nothing behind: no sale, no sequence movement. The number
 * is therefore returned to the pool and may be issued again later. This is a
 * deliberate trade-off: a gap in the sequence (e.g. `INV-000007` never printed)
 * is visible to customers as a possible missing receipt, whereas a *reused*
 * number can only ever be one that was never issued, printed or recorded in the
 * first place. Once a transaction commits, its number is permanently consumed
 * and is never reissued.
 *
 * **Format.** See {@see InvoiceNumber}: `{invoice_prefix}-{000001}`. The prefix
 * comes from the existing `business_settings.invoice_prefix` key of the same
 * settings system the Settings screen writes to, so there is exactly one
 * settings system and one place an operator can change the prefix.
 */
final class InvoiceNumberService
{
    /** Settings key holding the business' invoice prefix. */
    public const PREFIX_SETTING = 'invoice_prefix';

    /**
     * Allocate the next invoice number for a business.
     *
     * MUST be called from inside the transaction that creates the sale, so the
     * number and the document it identifies are committed or rolled back
     * together. Calling it outside a transaction would let the increment commit
     * independently of the sale and permanently burn numbers.
     *
     * @throws \RuntimeException when the sequence row cannot be created or read
     */
    public function allocate(int $businessId): string
    {
        $sequence = $this->lockSequenceRow($businessId);

        $number = (int) $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return InvoiceNumber::format($this->prefixFor($businessId), $number);
    }

    /**
     * The number the next sale of this business would receive, without
     * consuming it. Read-only, so it never takes the lock and never mutates.
     */
    public function peek(int $businessId): int
    {
        $row = InvoiceSequence::query()->forBusiness($businessId)->first();

        return $row === null ? InvoiceSequence::FIRST_SEQUENCE : (int) $row->next_number;
    }

    /**
     * Take the business' sequence row under a write lock, creating it on first
     * use.
     *
     * The row is created by the migration for every existing business, so the
     * insert path only runs for a business created afterwards. Two concurrent
     * first sales can reach it together; the unique index on `business_id`
     * makes exactly one insert win, and the loser simply reads the row the
     * winner committed instead of failing the sale.
     */
    private function lockSequenceRow(int $businessId): InvoiceSequence
    {
        $existing = InvoiceSequence::query()
            ->forBusiness($businessId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return InvoiceSequence::query()->create([
                'business_id' => $businessId,
                'next_number' => InvoiceSequence::FIRST_SEQUENCE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = InvoiceSequence::query()
                ->forBusiness($businessId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new \RuntimeException(
                    'Unable to initialise the invoice sequence for business '.$businessId.'.',
                );
            }

            return $row;
        }
    }

    /**
     * The business' configured invoice prefix, or the documented default.
     *
     * Read through the existing `business_settings` table and scoped to this
     * business, so one tenant can never borrow another tenant's prefix. A
     * missing, blank or unusable value is not an error — it falls back to
     * `InvoiceNumber::DEFAULT_PREFIX`.
     */
    private function prefixFor(int $businessId): string
    {
        $configured = BusinessSetting::query()
            ->forBusiness($businessId)
            ->where('key', self::PREFIX_SETTING)
            ->value('value');

        return InvoiceNumber::sanitizePrefix(is_string($configured) ? $configured : null);
    }
}
