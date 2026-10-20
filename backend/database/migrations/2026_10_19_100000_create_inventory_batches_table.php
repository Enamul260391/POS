<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: `inventory_batches` is a new table that layers lot/expiry
     * tracking on top of the existing `stock_movements` ledger. It does not
     * store a competing stock quantity — `remaining_quantity` is a derived
     * allocation that starts at `received_quantity` and is decremented by the
     * sale-side flow (STEP 3). `stock_movements` remains the single source of
     * truth for on-hand quantity.
     */
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // One purchase item maps to exactly one batch; nullable so the
            // table can also hold backfilled or manually created batches that
            // have no purchase document behind them.
            $table->foreignId('purchase_item_id')
                ->unique()
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('batch_number', 50);
            $table->date('received_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('received_quantity', 15, 4)->default(0);
            $table->decimal('remaining_quantity', 15, 4)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->decimal('mrp', 15, 2)->nullable();
            $table->string('selling_code', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'batch_number']);
            $table->index(['business_id', 'product_id']);
            $table->index('expiry_date');
            $table->index('purchase_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};