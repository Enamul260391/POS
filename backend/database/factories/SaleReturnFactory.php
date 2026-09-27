<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleReturn>
 */
class SaleReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'sale_id' => Sale::factory(),
            'customer_id' => null,
            'return_number' => 'SR-'.fake()->unique()->numberBetween(1, 100000000),
            'return_date' => now()->toDateString(),
            'reason' => null,
            'subtotal' => '0.00',
            'tax' => '0.00',
            'refund_amount' => '0.00',
            'status' => SaleReturn::STATUS_COMPLETED,
            'created_by' => null,
        ];
    }
}
