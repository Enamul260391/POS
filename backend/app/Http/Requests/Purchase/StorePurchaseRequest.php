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
 * Payload of `POST /api/v1/purchases`.
 *
 * Header fields plus an `items` collection, mirroring the actual `purchases`
 * and `purchase_items` tables (Phase 16). Deliberately **not** accepted:
 *
 *  - `business_id` — the tenant comes from the authenticated user;
 *  - `subtotal` / `grand_total` / `due_amount` / `payment_status` / `status`
 *    / `created_by` — all computed or derived server-side by
 *    {@see \App\Services\Purchase\PurchaseService::create()}, never trusted
 *    from the client;
 *  - any stock quantity — inventory only moves through the ledger.
 *
 * `supplier_id` and every `items.*.product_id` must exist **and belong to
 * the authenticated business**, so a cross-tenant reference fails validation
 * with a 422 before any write happens.
 */
class StorePurchaseRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', $this->supplierRule()],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'purchase_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'integer', 'min:1', $this->productRule()],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.mrp' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'items.*.selling_code' => ['nullable', 'string', 'max:50'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
        ];
    }

    /**
     * Trim strings and normalise blank optional values to `null`.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'invoice_number' => $this->filled('invoice_number')
                ? trim((string) $this->input('invoice_number'))
                : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'items' => $this->normaliseItems($this->input('items')),
        ]);
    }

    /**
     * Trim each item `selling_code` and normalise blank strings to `null`,
     * following the project convention for optional identifiers.
     *
     * @param  mixed  $items
     * @return mixed
     */
    protected function normaliseItems(mixed $items): mixed
    {
        if (! is_array($items)) {
            return $items;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! array_key_exists('selling_code', $item)) {
                continue;
            }

            $code = $item['selling_code'];

            $items[$index]['selling_code'] = is_string($code) && trim($code) !== ''
                ? trim($code)
                : null;
        }

        return $items;
    }

    /**
     * The supplier must exist, belong to the authenticated business and not
     * be soft-deleted: a purchase can never reference another tenant.
     */
    protected function supplierRule(): Exists
    {
        return Rule::exists('suppliers', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    /**
     * Every purchased product must exist in the authenticated business and
     * not be soft-deleted.
     */
    protected function productRule(): Exists
    {
        return Rule::exists('products', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }
}