<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An auditable movement history of what a customer owes the business.
 *
 * `customers.opening_balance` is a single static number: it records where the
 * relationship started and nothing that happened afterwards. Nothing in the
 * schema could answer "what does this customer owe right now", so a credit sale
 * and a return against one both vanished the moment they were written — the sale
 * stored `due_amount`, but no later return ever reduced it, and rewriting it
 * would have falsified the invoice the customer was originally given.
 *
 * This table is the replacement: one immutable row per movement, so the current
 * balance is the sum of what happened rather than a number that gets edited.
 *
 * ## Sign convention
 *
 * **Positive = the customer owes the business. Negative = the business owes the
 * customer.** One rule, applied consistently, with no per-type special casing:
 *
 *     OPENING_BALANCE   +5,000   they started owing
 *     SALE_DUE           +2,000   bought on credit
 *     CUSTOMER_PAYMENT   -1,000   paid us back
 *     SALE_RETURN          -500   goods came back, so less is owed
 *     --------------------------
 *     Outstanding        +5,500
 *
 * A return large enough to exceed what was owed carries the balance negative,
 * which correctly reads as credit the business now owes back — an advance
 * rather than a fabricated negative receivable.
 *
 * ## Why nothing here is mutable
 *
 * There is no cached `balance` column and no path that updates or deletes a
 * row. The sum of the rows *is* the balance, so an entry can never drift from
 * the events that caused it, and history stays complete. `reference_type` /
 * `reference_id` follow the `stock_movements` and `cash_register_transactions`
 * convention already used in this project.
 *
 * Indexes follow the same shape: one composite for the balance aggregate
 * (`business_id, customer_id`, which is always both the filter and the group
 * key), plus lookups by type and by the referenced document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            // Signed: positive is money owed to the business, negative is money
            // the business owes back. Never stored unsigned.
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // The balance aggregate: tenant + customer, always both.
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
    }
};