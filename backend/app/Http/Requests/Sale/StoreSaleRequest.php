<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Payload of `POST /api/v1/sales`.
 */
class StoreSaleRequest extends FormRequest
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
            'customer_id' => ['nullable', 'integer', $this->customerRule()],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'sale_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'payment_method' => ['nullable', 'string', Rule::in(\App\Models\Payment::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'integer', 'min:1', $this->productRule()],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'invoice_number' => $this->filled('invoice_number') ? trim((string) $this->input('invoice_number')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'payment_reference' => $this->filled('payment_reference') ? trim((string) $this->input('payment_reference')) : null,
        ]);
    }

    protected function customerRule(): Exists
    {
        return Rule::exists('customers', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    protected function productRule(): Exists
    {
        return Rule::exists('products', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }
}
