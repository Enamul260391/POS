<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\StockMovement;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/products/{product}/stock-adjustments`.
 *
 * `type` uses the `stock_movements.type` vocabulary the POS UI records
 * (`receive`, `remove`, `set`) and `quantity` is always the amount the user
 * counted, never a signed ledger value: {@see \App\Services\Inventory\InventoryService}
 * converts it into the signed change the ledger stores.
 *
 * `business_id`, `product_id`, `created_by` and `reference_*` are derived
 * server side and cannot be supplied by the client.
 */
class StockAdjustmentRequest extends FormRequest
{
    use ResolvesBusiness;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(StockMovement::ADJUSTMENT_TYPES)],
            'quantity' => [
                'required',
                'numeric',
                'min:0',
                'max:'.Quantity::MAX,
                'decimal:0,4',
                Rule::when($this->requiresPositiveQuantity(), ['gt:0']),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Trim the note so a whitespace-only reason is stored as "no reason".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $this->merge([
                'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
            ]);
        }
    }

    /**
     * Receiving and removing move stock by an amount, so zero is meaningless;
     * setting a count may legitimately be zero (an empty shelf).
     */
    private function requiresPositiveQuantity(): bool
    {
        return in_array(
            $this->input('type'),
            [StockMovement::TYPE_RECEIVE, StockMovement::TYPE_REMOVE],
            true,
        );
    }
}
