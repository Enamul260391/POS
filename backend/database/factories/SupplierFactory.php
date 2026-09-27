<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The `suppliers` table carries no unique index on phone/email, so the
     * faker unique-generator pool is only used to keep fixture data readable;
     * tests that care about a specific value override it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Supplier '.fake()->unique()->numberBetween(1, 100000000),
            'phone' => fake()->numerify('01#########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'opening_balance' => '0.00',
            'status' => Supplier::STATUS_ACTIVE,
        ];
    }
}