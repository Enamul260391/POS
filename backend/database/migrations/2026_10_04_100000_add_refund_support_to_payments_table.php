<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let `payments` record an outbound refund as well as an inbound payment.
 *
 * The table could only ever mean "money the business received": it pointed at a
 * sale or a purchase and nothing else. A return handed money back, but had no
 * row to say so — only a cash-register movement, which does not exist for a
 * card, bank or cheque refund at all. A report could therefore see the sale that
 * was paid and the return that reversed it, but never the refund itself.
 *
 * Two additions, both minimal:
 *
 *  - **`sale_return_id`** — nullable, so every existing payment keeps pointing
 *    exactly where it did. A refund is tied to the return that caused it; an
 *    ordinary payment keeps `sale_return_id = NULL`.
 *  - **`type`** — `payment` or `refund`. Without it a refund row would be
 *    indistinguishable from an inbound payment, because `payment_method` already
 *    carries the method and says nothing about direction. The vocabulary
 *    follows `CashRegisterTransaction::type`, where `refund` is already the
 *    established word for money leaving the drawer.
 *
 * A refund deliberately leaves `sale_id` NULL. Setting it would make the row
 * appear in `$sale->payments()` and in every sale payment report as an
 * inbound collection, which is the opposite of what it is. The path back to the
 * sale is `payment -> sale_return -> sale`.
 *
 * ## Duplicate refund protection
 *
 * `unique(sale_return_id, payment_method)` allows at most one refund of a given
 * method per return. It is safe for existing data because SQL treats NULLs as
 * distinct in a unique index, so every ordinary sale and purchase payment
 * (all of which have `sale_return_id IS NULL`) is unaffected, and the index is
 * created after the column so no row has to be rewritten.
 *
 * The one deliberate consequence: a return is settled exactly once, when it is
 * created. That matches how the return flow already works — the settlement is
 * part of the same transaction that writes the return — and it is enforced here
 * at the database level rather than by a PHP check that a concurrent request
 * could race past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('sale_return_id')
                ->nullable()
                ->after('purchase_id')
                ->constrained()
                ->nullOnDelete();

            // Defaults to `payment` so every pre-existing row is an inbound
            // payment without a single UPDATE, which is what keeps the migration
            // safe to run against live data.
            $table->string('type', 20)->default('payment')->after('sale_return_id');

            $table->index(['business_id', 'sale_return_id']);
            $table->index('type');

            // The duplicate-refund guarantee itself. `unique()`, not `index()`:
            // this is what makes a second refund of the same method against the
            // same return impossible at the database level, rather than merely
            // fast to look up.
            $table->unique(['sale_return_id', 'payment_method'], 'payments_sale_return_method_unique');
        });
    }

    public function down(): void
    {
        // Order matters here, and it is database-dependent. MySQL lets a
        // composite index back a foreign key and then refuses to drop that index
        // while the key still exists ("needed in a foreign key constraint"), so
        // the key has to go first — which in turn takes the indexes built on that
        // column with it.
        //
        // Because of that, each step is guarded rather than assumed: on SQLite
        // nothing cascades and on MySQL some of it does, and a rollback that
        // fails halfway leaves the table in a state no later migration can fix.
        $existing = array_map(
            static fn (array $index): string => $index['name'],
            Schema::getIndexes('payments')
        );

        if (in_array('payments_sale_return_id_foreign', $existing, true)) {
            Schema::table('payments', static function (Blueprint $table): void {
                $table->dropForeign(['sale_return_id']);
            });
        }

        // Dropping a column removes every index built on it, so re-read the
        // list rather than trusting what was true a moment ago.
        $existing = array_map(
            static fn (array $index): string => $index['name'],
            Schema::getIndexes('payments')
        );

        foreach (['payments_sale_return_method_unique', 'payments_business_id_sale_return_id_index', 'payments_type_index'] as $index) {
            if (in_array($index, $existing, true)) {
                Schema::table('payments', static function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('payments', static function (Blueprint $table): void {
            $table->dropColumn(['sale_return_id', 'type']);
        });
    }
};