<?php

declare(strict_types=1);

namespace App\Http\Requests\SaleReturn;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of `POST /api/v1/sale-returns`.
 */
class StoreSaleReturnRequest extends FormRequest
{
    use ResolvesBusiness;

    public const MAX_ITEMS = 500;

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
            'sale_id' => ['required', 'integer', 'min:1'],
            'return_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'return_number' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.sale_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'return_number' => $this->filled('return_number') ? trim((string) $this->input('return_number')) : null,
        ]);
    }
}
