<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Names are unique across the whole test run because `categories` carries a
     * `(business_id, name)` unique index and the faker unique-generator pool is
     * shared — tests that care about a specific name override it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Category '.fake()->unique()->numberBetween(1, 100000000);

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => Category::STATUS_ACTIVE,
        ];
    }
}
