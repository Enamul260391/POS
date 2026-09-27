<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opened_by' => $this->opened_by,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_balance' => $this->opening_balance,
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closing_balance' => $this->closing_balance,
            'expected_balance' => $this->expected_balance,
            'difference' => $this->difference,
            'status' => $this->status,
            'notes' => $this->notes,
            'current_balance' => $this->when(isset($this->current_balance), fn (): string => Money::format($this->current_balance)),
            'transaction_summary' => $this->when(isset($this->transaction_summary), fn (): array => $this->transaction_summary),
            'transactions' => CashRegisterTransactionResource::collection($this->whenLoaded('transactions')),
            'opened_by_user' => $this->whenLoaded('openedBy', fn (): array => ['id' => $this->openedBy->id, 'name' => $this->openedBy->name]),
            'closed_by_user' => $this->whenLoaded('closedBy', fn (): array => ['id' => $this->closedBy->id, 'name' => $this->closedBy->name]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
