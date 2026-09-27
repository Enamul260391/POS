<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Columns with sensible table defaults (currency, timezone, status) are
     * still set explicitly so a test never depends on the migration defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'phone' => fake()->numerify('01#########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'status' => Business::STATUS_ACTIVE,
        ];
    }
}
