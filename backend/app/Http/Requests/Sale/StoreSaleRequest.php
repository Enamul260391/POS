<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\InventoryBatch;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    use ResolvesBusiness;

    public const MAX_ITEMS = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', $this->customerRule()],
            'invoice_number' => ['prohibited'],
            'sale_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'payment_method' => ['nullable', 'string', Rule::in(Payment::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'integer', 'min:1', $this->productRule()],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.batch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // invoice_number is intentionally absent: normalising it here would
        // put the key into the payload and trip its own prohibited rule on
        // every single request.
        $this->merge([
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

    protected function batchRule(): Exists
    {
        return Rule::exists('inventory_batches', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = $this->input('items', []);
                if (! is_array($items)) {
                    return;
                }

                foreach ($items as $index => $item) {
                    if (! is_array($item) || ! array_key_exists('batch_id', $item) || ! $item['batch_id']) {
                        continue;
                    }

                    $batchId = (int) $item['batch_id'];

                    $batch = InventoryBatch::query()
                        ->forBusiness($this->businessId())
                        ->whereKey($batchId)
                        ->first();

                    if ($batch === null) {
                        $validator->errors()->add(
                            "items.{$index}.batch_id",
                            'The selected batch does not belong to your business.',
                        );
                        continue;
                    }

                    if ((int) $item['product_id'] !== (int) $batch->product_id) {
                        $validator->errors()->add(
                            "items.{$index}.batch_id",
                            'The selected batch does not belong to the product of this sale line.',
                        );
                    }
                }
            },
        ];
    }
}
