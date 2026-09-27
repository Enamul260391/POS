<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'invoice_number' => 'SINV-'.fake()->unique()->numberBetween(1, 100000000),
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'subtotal' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'grand_total' => '0.00',
            'paid_amount' => '0.00',
            'due_amount' => '0.00',
            'payment_status' => Sale::PAYMENT_STATUS_PENDING,
            'status' => Sale::STATUS_DRAFT,
            'notes' => null,
            'cashier_id' => null,
        ];
    }
}
