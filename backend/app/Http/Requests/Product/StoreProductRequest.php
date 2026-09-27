<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * Payload of `POST /api/v1/products`.
 *
 * Mirrors the actual `products` table (Phase 16): no `stock` column exists, so
 * on-hand quantity is never posted directly. `opening_stock` is accepted once,
 * at creation time, and is written to the ledger as a stock movement instead
 * of being stored on the product.
 *
 * `business_id` is intentionally absent — the tenant comes from the
 * authenticated user.
 */
class StoreProductRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer', $this->categoryRule()],
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:50', $this->uniqueRule('sku')],
            'barcode' => ['nullable', 'string', 'max:100', $this->uniqueRule('barcode')],
            'description' => ['nullable', 'string', 'max:1000'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'unit' => ['nullable', 'string', 'max:20'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'image' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(Product::STATUSES)],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
        ];
    }

    /**
     * Reject opening stock on products that are not stock tracked.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('unit') !== Product::UNIT_SERVICE) {
                    return;
                }

                if (Quantity::isPositive($this->input('opening_stock', 0))) {
                    $validator->errors()->add(
                        'opening_stock',
                        'Opening stock cannot be set for a service product.',
                    );
                }
            },
        ];
    }

    /**
     * Trim strings and normalise blank optional values to `null`, so the
     * `(business_id, sku)` and `(business_id, barcode)` unique indexes treat
     * "no SKU" as NULL (which they allow more than once) rather than `''`.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'sku' => $this->filled('sku') ? trim((string) $this->input('sku')) : null,
            'barcode' => $this->filled('barcode') ? trim((string) $this->input('barcode')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'image' => $this->filled('image') ? trim((string) $this->input('image')) : null,
        ]);
    }

    /**
     * The category must belong to the authenticated business and must not be
     * soft-deleted: a product can never be attached to another tenant's
     * category.
     */
    protected function categoryRule(): Exists
    {
        return Rule::exists('categories', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    /**
     * Business-scoped uniqueness for `sku` / `barcode`.
     *
     * Soft-deleted products are included because the unique index does not
     * exclude them; a trashed value stays taken.
     */
    protected function uniqueRule(string $column): Unique
    {
        return Rule::unique('products', $column)->where('business_id', $this->businessId());
    }
}
