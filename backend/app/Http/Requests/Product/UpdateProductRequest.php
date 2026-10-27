<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `PUT|PATCH /api/v1/products/{product}`.
 *
 * Master data only. `business_id` is never validated (the tenant cannot
 * change), and neither `stock` nor `opening_stock` is part of the contract:
 * inventory moves through `POST /api/v1/products/{product}/stock-adjustments`
 * and the ledger, so a client that posts a stock field simply changes nothing.
 *
 * A product is therefore **never** a way to add stock, in either direction:
 * creation rejects `opening_stock` outright, and an update has no field that
 * could carry a quantity.
 */
class UpdateProductRequest extends FormRequest
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
            'category_id' => ['sometimes', 'nullable', 'integer', $this->categoryRule()],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:50', $this->uniqueRule('sku')],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', $this->uniqueRule('barcode')],
            'unit' => ['sometimes', 'required', 'string', 'max:20'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Product::STATUSES)],
        ];
    }

    /**
     * Trim strings and normalise blank optional values to `null`.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'unit'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => trim((string) $this->input($key))]);
            }
        }

        foreach (['sku', 'barcode', 'image'] as $key) {
            if ($this->has($key)) {
                $this->merge([
                    $key => $this->filled($key) ? trim((string) $this->input($key)) : null,
                ]);
            }
        }
    }

    /**
     * The category must belong to the authenticated business and not be
     * soft-deleted (a cross-business category is rejected as invalid).
     */
    protected function categoryRule(): Exists
    {
        return Rule::exists('categories', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }

    /**
     * Business-scoped uniqueness for `sku` / `barcode`, ignoring this product.
     */
    protected function uniqueRule(string $column): Unique
    {
        return Rule::unique('products', $column)
            ->where('business_id', $this->businessId())
            ->ignore((int) $this->route('product'));
    }
}
