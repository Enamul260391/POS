<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'purchase_item_id' => null,
            'batch_number' => 'BATCH-'.fake()->unique()->bothify('####-????'),
            'received_date' => now()->toDateString(),
            'expiry_date' => null,
            'received_quantity' => '10.0000',
            'remaining_quantity' => '10.0000',
            'cost_price' => '100.00',
            'selling_price' => '150.00',
            'mrp' => '160.00',
            'selling_code' => null,
        ];
    }
}
