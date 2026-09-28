<?php

declare(strict_types=1);

use App\Support\InvoiceNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `sales.invoice_number` a real, unique, server-owned identity.
 *
 * Before this migration the column was nullable and unconstrained, and
 * `SaleService::create()` stored whatever the client sent — or `NULL`. This
 * migration closes both gaps without touching a single sale's identity:
 *
 *  1. **Backfill.** Every `NULL` invoice number is replaced with a generated
 *     one. No sale is deleted, no `id` is changed, and no existing non-null
 *     number is rewritten, so historical documents keep the number they were
 *     issued with.
 *  2. **Sequence alignment.** Each business' `invoice_sequences.next_number` is
 *     raised above every number already issued to that business, so the next
 *     sale cannot collide with one that already exists.
 *  3. **Uniqueness.** `UNIQUE (business_id, invoice_number)` makes a duplicate
 *     impossible even if application code is bypassed.
 *  4. **NOT NULL.** Applied *only after* the backfill, so it can never fail on
 *     a row that was not handled first.
 *
 * Determinism: backfilled rows are numbered in ascending `id` order per
 * business, so the same data always produces the same numbers.
 */
return new class extends Migration
{
    /** Settings key holding the business' invoice prefix. */
    private const PREFIX_SETTING = 'invoice_prefix';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->backfillMissingInvoiceNumbers();
        $this->alignSequences();
        $this->enforceUniqueness();
        $this->requireInvoiceNumber();
    }

    /**
     * Give every `NULL` invoice number a deterministic, unique value.
     *
     * The counter deliberately starts *above* the highest number already in use
     * by the business rather than at 1, so a legacy number such as `S-41` can
     * never be reissued as a generated one. Candidates that would still collide
     * (compared case-insensitively, because MySQL's default collation is) are
     * skipped.
     */
    private function backfillMissingInvoiceNumbers(): void
    {
        $sales = DB::table('sales')
            ->select('id', 'business_id', 'invoice_number')
            ->orderBy('id')
            ->get();

        if ($sales->isEmpty()) {
            return;
        }

        $prefixes = $this->prefixesByBusiness();
        $issued = [];
        $next = [];

        // First pass: learn what is already taken, and how high the numbers go.
        foreach ($sales as $sale) {
            $existing = $this->cleanNumber($sale->invoice_number);
            if ($existing === null) {
                continue;
            }

            $businessId = (int) $sale->business_id;
            $issued[$businessId][mb_strtolower($existing)] = true;
            $next[$businessId] = max(
                $next[$businessId] ?? 0,
                $this->trailingNumber($existing),
            );
        }

        // Second pass: fill the gaps, in id order, above that high-water mark.
        foreach ($sales as $sale) {
            if ($this->cleanNumber($sale->invoice_number) !== null) {
                continue;
            }

            $businessId = (int) $sale->business_id;
            $prefix = $prefixes[$businessId] ?? InvoiceNumber::DEFAULT_PREFIX;
            $sequence = ($next[$businessId] ?? 0) + 1;

            do {
                $candidate = InvoiceNumber::format($prefix, $sequence);
                $sequence++;
            } while (isset($issued[$businessId][mb_strtolower($candidate)]));

            $issued[$businessId][mb_strtolower($candidate)] = true;
            $next[$businessId] = $sequence - 1;

            DB::table('sales')
                ->where('id', (int) $sale->id)
                ->update(['invoice_number' => $candidate]);
        }
    }

    /**
     * Raise every business' counter above the numbers already in use.
     *
     * Reads the *stored* numbers rather than anything computed above, so the
     * sequence is also correct for a business whose invoices were all issued
     * before this feature existed.
     */
    private function alignSequences(): void
    {
        foreach (DB::table('sales')->distinct()->pluck('business_id') as $businessId) {
            $businessId = (int) $businessId;

            $highest = DB::table('sales')
                ->where('business_id', $businessId)
                ->whereNotNull('invoice_number')
                ->pluck('invoice_number')
                ->reduce(
                    fn (int $carry, $value): int => max($carry, $this->trailingNumber((string) $value)),
                    0,
                );

            // `next_number` is the value the *next* sale receives, so it is one
            // past the highest number already issued — and never below 1.
            $nextNumber = max($highest + 1, 1);

            $updated = DB::table('invoice_sequences')
                ->where('business_id', $businessId)
                ->update(['next_number' => $nextNumber, 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table('invoice_sequences')->insert([
                    'business_id' => $businessId,
                    'next_number' => $nextNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * One invoice number per business, enforced by the database itself.
     */
    private function enforceUniqueness(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['business_id', 'invoice_number'], 'sales_business_invoice_unique');
        });
    }

    /**
     * Only safe once every `NULL` has been backfilled above.
     */
    private function requireInvoiceNumber(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('invoice_number', 50)->nullable(false)->change();
        });
    }

    /**
     * Configured prefix per business, sanitised exactly as the service does.
     *
     * @return array<int, string>
     */
    private function prefixesByBusiness(): array
    {
        $prefixes = [];

        foreach (DB::table('business_settings')->where('key', self::PREFIX_SETTING)->get() as $setting) {
            $prefixes[(int) $setting->business_id] = InvoiceNumber::sanitizePrefix(
                is_string($setting->value) ? $setting->value : null,
            );
        }

        return $prefixes;
    }

    /** A trimmed, non-empty invoice number, or `null` when the row has none. */
    private function cleanNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The largest number an existing invoice number ends with, or 0.
     *
     * `INV-000042` → 42, `S-41` → 41, `ABC` → 0. The sequence only has to stay
     * above these, so reading it loosely is safe; reading it strictly is not.
     */
    private function trailingNumber(string $invoiceNumber): int
    {
        if (preg_match('/(\d+)\s*$/', $invoiceNumber, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * Reverse the constraint changes.
     *
     * Backfilled numbers are real business documents now, so they stay in place
     * rather than being nulled out again — relaxing the column to "NULL is
     * allowed" must not un-issue an invoice a customer already holds.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('invoice_number', 50)->nullable()->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_business_invoice_unique');
        });
    }
};
