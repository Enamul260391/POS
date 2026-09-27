<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseCategory> */
class ExpenseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => \App\Models\Business::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'status' => ExpenseCategory::STATUS_ACTIVE,
        ];
    }
}
