<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Payload of `PUT|PATCH /api/v1/purchases/{purchase}`.
 *
 * A purchase that has posted stock movements is finalized, so only safe
 * metadata is really editable: supplier (same business), invoice number,
 * purchase date, notes and the purchase-side paid amount. `business_id`,
 * `subtotal`, `discount`, `tax`, `grand_total`, `payment_status`, `status`
 * and `created_by` are derived server-side.
 *
 * `items` and the computed total columns are still *validated* here (rather
 * than ignored) so that an unchanged full PUT re-send is recognised and a
 * genuine change produces an explicit 409 from the service instead of being
 * silently dropped — client input never reaches the ledger, and totals are
 * never taken from the client.
 */
class UpdatePurchaseRequest extends FormRequest
{
    use ResolvesBusiness;

    /** Maximum number of lines a single purchase may carry. */
    public const MAX_ITEMS = 500;

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
            'supplier_id' => ['sometimes', 'required', 'integer', $this->supplierRule()],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'purchase_date' => ['sometimes', 'required', 'date'],
            'paid_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Structural fields — accepted for comparison only; the service
            // rejects any value that differs from the stored document.
            'subtotal' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'discount' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'tax' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'grand_total' => ['sometimes', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items' => ['sometimes', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required_with:items', 'integer', 'min:1', $this->productRule()],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
        ];
    }

    /**
     * Trim provided strings and normalise blank optional values to `null`.
     */
    protected function prepareForValidation(): void
    {
        foreach (['invoice_number', 'notes'] as $key) {
            if ($this->has($key)) {
                $this->merge([
                    $key => $this->filled($key) ? trim((string) $this->input($key)) : null,
                ]);
            }
        }
    }

    /**
     * A supplier change must stay inside the authenticated business and must
     * not reference a soft-deleted supplier.
     */
    protected function supplierRule(): Exists
    {
        return Rule::exists('suppliers', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    /**
     * Any re-sent item must reference a product of the authenticated
     * business (identical re-sends trivially satisfy this).
     */
    protected function productRule(): Exists
    {
        return Rule::exists('products', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }
}