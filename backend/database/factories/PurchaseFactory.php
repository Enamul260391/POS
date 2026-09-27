<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Creates only the purchase **header** with zero totals — no items and no
     * stock movements, so a factory purchase has never affected inventory
     * (tests that need a stock-in purchase go through the API). Tests must
     * pass `business_id` **and** a `supplier_id` of the same business
     * explicitly to keep fixtures tenant-consistent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'supplier_id' => Supplier::factory(),
            'invoice_number' => 'PINV-'.fake()->unique()->numberBetween(1, 100000000),
            'purchase_date' => now()->toDateString(),
            'subtotal' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'grand_total' => '0.00',
            'paid_amount' => '0.00',
            'due_amount' => '0.00',
            'payment_status' => Purchase::PAYMENT_STATUS_PENDING,
            'status' => Purchase::STATUS_DRAFT,
            'notes' => null,
            'created_by' => null,
        ];
    }
}