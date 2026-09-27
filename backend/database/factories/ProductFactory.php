<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * No `stock` attribute exists: on-hand quantity lives only in
     * `stock_movements`, so a factory product starts with an empty ledger and
     * tests create movements explicitly (or through the API) when they need a
     * balance.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'category_id' => null,
            'name' => 'Product '.fake()->unique()->numberBetween(1, 100000000),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-????')),
            'barcode' => fake()->unique()->numerify('##############'),
            'description' => fake()->sentence(),
            'cost_price' => '10.00',
            'selling_price' => '15.00',
            'tax_rate' => '0.00',
            'unit' => 'pcs',
            'low_stock_threshold' => 5,
            'image' => null,
            'status' => Product::STATUS_ACTIVE,
        ];
    }
}
