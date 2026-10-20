<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: links each sale line to the inventory batch it was
     * sold from. Nullable so all existing (pre-batch) sales keep working
     * without an invented batch. `nullOnDelete` preserves the sale line
     * — the sale itself is the source of truth, not the batch.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('inventory_batches')
                ->nullOnDelete()
                ->after('product_id');
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};

