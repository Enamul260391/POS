<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Product;
use App\Services\InventoryBatch\InventoryBatchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Query string of `GET /api/v1/inventory-batches`.
 *
 * The list is always scoped to the authenticated user's business. A `product_id`
 * from another business simply matches nothing — the business scope is applied
 * first, so the response never reveals whether the product exists elsewhere.
 */
class IndexInventoryBatchRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

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
            'product_id' => ['nullable', 'integer', 'min:1', $this->productRule()],
            ...$this->paginationRules(InventoryBatchService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised filters for {@see InventoryBatchService::paginate()}.
     *
     * @return array{product_id: int|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'product_id' => $this->filled('product_id') ? (int) $this->input('product_id') : null,
            'per_page' => $this->perPage(InventoryBatchService::DEFAULT_PER_PAGE),
        ];
    }

    /**
     * A filtered product must exist in the authenticated business and not be
     * soft-deleted.
     */
    protected function productRule(): Exists
    {
        return Rule::exists('products', 'id')
            ->where('business_id', $this->businessId())
            ->whereNull('deleted_at');
    }
}
