<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of `PUT|PATCH /api/v1/sales/{sale}` — safe metadata only.
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
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:50'],
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
        foreach (['invoice_number', 'notes'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => $this->filled($key) ? trim((string) $this->input($key)) : null]);
            }
        }
    }
}
