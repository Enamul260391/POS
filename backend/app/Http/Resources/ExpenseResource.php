<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'amount' => $this->amount,
            'expense_date' => $this->expense_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'category' => ExpenseCategoryResource::make($this->whenLoaded('expenseCategory')),
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'cash_register_transaction_id' => $this->when(
                $this->relationLoaded('cashRegisterTransactions'),
                fn (): ?int => $this->cashRegisterTransactions->first()?->id,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
