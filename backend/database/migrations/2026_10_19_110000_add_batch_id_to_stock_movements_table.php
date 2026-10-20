<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: links an existing stock movement to the inventory batch
     * it affected. Nullable so all historical movements (and manual
     * adjustments) keep working without a batch. `nullOnDelete` preserves the
     * ledger row if a batch is ever removed — the movement is the source of
     * truth, not the batch.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('inventory_batches')
                ->nullOnDelete()
                ->after('reference_id');
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};