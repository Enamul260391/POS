<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => \App\Models\Business::factory(),
            'expense_category_id' => null,
            'amount' => fake()->randomFloat(2, 1, 5000),
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_CASH,
            'reference' => fake()->optional()->bothify('EXP-#####'),
            'description' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
