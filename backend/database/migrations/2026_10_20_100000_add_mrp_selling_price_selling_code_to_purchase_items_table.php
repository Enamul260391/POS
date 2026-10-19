<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: `mrp`, `selling_price` and `selling_code` are nullable
     * purchase-item attributes, so every existing row and every older API
     * client stays valid — an omitted value simply stores NULL. Money columns
     * mirror the existing `decimal(15, 2)` convention; the code mirrors the
     * product `selling_code` length (`string(50)`) with no uniqueness, because
     * no business rule requires it to be distinct.
     *
     * These values belong to the individual purchase item record, not the
     * global product record, and they never affect purchase totals or the
     * stock ledger.
     */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('mrp', 15, 2)->nullable()->after('discount');
            $table->decimal('selling_price', 15, 2)->nullable()->after('mrp');
            $table->string('selling_code', 50)->nullable()->after('selling_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['mrp', 'selling_price', 'selling_code']);
        });
    }
};
