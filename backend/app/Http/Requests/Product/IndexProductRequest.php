<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Product;
use App\Services\Product\ProductService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/products`.
 *
 * Every filter is applied in SQL by {@see ProductService::paginate()}; nothing
 * is loaded and filtered in PHP, and `sort` is restricted to the columns the
 * service is willing to order by.
 */
class IndexProductRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(Product::STATUSES)],
            'sku' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:100'],
            ...$this->orderingRules(ProductService::SORTABLE_COLUMNS),
            ...$this->paginationRules(ProductService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see ProductService::paginate()}.
     *
     * @return array{search: string, category_id: int|null, status: string|null, sku: string|null, barcode: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'category_id' => $this->filled('category_id') ? (int) $this->input('category_id') : null,
            'status' => $this->input('status'),
            'sku' => $this->filled('sku') ? trim((string) $this->input('sku')) : null,
            'barcode' => $this->filled('barcode') ? trim((string) $this->input('barcode')) : null,
            'sort' => (string) $this->input('sort', ProductService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', ProductService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(ProductService::DEFAULT_PER_PAGE),
        ];
    }
}
