<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashRegisterTransaction> */
class CashRegisterTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => \App\Models\Business::factory(),
            'cash_register_id' => function (array $attributes): int {
                return CashRegister::factory()->create(['business_id' => $attributes['business_id']])->getKey();
            },
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => fake()->randomFloat(2, 1, 1000),
            'reference_type' => null,
            'reference_id' => null,
            'note' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
