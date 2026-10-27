<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the cost of the units on a sale line.
 *
 * `sale_items` stored only the selling price, so the cost of goods sold for a
 * historical invoice could never be recovered and `GET /reports/profit-loss` had
 * to answer `available: false`. This adds the column the report needs and, when
 * a trustworthy source still exists, fills it from the product's current
 * `cost_price`.
 *
 * The backfill is explicitly a best-effort reconstruction: rows written before
 * this migration get the product's cost as it stands today, which is the closest
 * approximation the schema allows. Rows written after it carry a true snapshot
 * taken at the moment of the sale, so the reading becomes exact over time.
 *
 * `products.cost_price` was removed from the schema, so on a fresh database the
 * source column never existed and historical unit costs are genuinely unknown:
 * they stay `NULL` and are never replaced with `0.00`.
 *
 * Nullable rather than defaulted: a service line has no product and therefore no
 * cost, and an absent cost must stay distinguishable from a genuine zero cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('unit_price');
        });

        // Backfill historical lines only when a trustworthy source still exists.
        //
        // `products.cost_price` was removed from the schema. On a fresh database
        // it was never there, so historical unit costs are genuinely unknown and
        // must stay NULL — never be replaced with `0.00`. On existing databases
        // where the column survives, historical lines are approximated with the
        // product's current cost, exactly as before.
        //
        // The correlated subquery is deliberate: `Query Builder::update()` on a
        // join is rewritten by SQLite into `... where rowid in (select ... join
        // products ...)`, where the joined `products.cost_price` is no longer in
        // scope for the SET expression, so the statement fails with "no such
        // column". A correlated scalar subquery resolves the product inside the
        // UPDATE itself and runs identically on SQLite and MySQL/MariaDB.
        //
        // It reads `products` directly rather than through Eloquent, so a
        // soft-deleted product still contributes its stored cost instead of
        // nulling the backfill.
        if (! Schema::hasColumn('products', 'cost_price')) {
            return;
        }

        DB::table('sale_items')
            ->whereNotNull('sale_items.product_id')
            ->update([
                'sale_items.unit_cost' => DB::raw(
                    '(SELECT cost_price FROM products WHERE products.id = sale_items.product_id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
