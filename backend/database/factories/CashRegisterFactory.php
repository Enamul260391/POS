<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashRegister> */
class CashRegisterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => \App\Models\Business::factory(),
            'opened_by' => null,
            'opened_at' => now(),
            'opening_balance' => '0.00',
            'closed_by' => null,
            'closed_at' => null,
            'closing_balance' => null,
            'expected_balance' => null,
            'difference' => null,
            'status' => CashRegister::STATUS_CLOSED,
            'notes' => null,
        ];
    }
}
