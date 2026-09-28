<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of `PUT|PATCH /api/v1/sales/{sale}` — safe metadata only.
 *
 * `invoice_number` is deliberately **not** accepted here. It is allocated by
 * `InvoiceNumberService` when the sale is created, it is unique per business,
 * and the column is `NOT NULL`; letting an edit write it would let a client
 * either erase a real invoice number or collide with another sale. The field is
 * still declared so a legacy client that sends it gets a clear rejection
 * instead of a silently ignored key.
 */
class UpdateSaleRequest extends FormRequest
{
    use ResolvesBusiness;

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
            'invoice_number' => ['prohibited'],
            'sale_date' => ['sometimes', 'required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'subtotal' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'discount' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'tax' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'grand_total' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items' => ['sometimes', 'array', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('notes')) {
            $this->merge(['notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null]);
        }
    }
}
