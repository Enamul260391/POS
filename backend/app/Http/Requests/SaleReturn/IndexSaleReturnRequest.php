<?php

declare(strict_types=1);

namespace App\Http\Requests\SaleReturn;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Services\SaleReturn\SaleReturnService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of `GET /api/v1/sale-returns`.
 */
class IndexSaleReturnRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

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
            'sale_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:20'],
            ...$this->orderingRules(SaleReturnService::SORTABLE_COLUMNS),
            ...$this->paginationRules(SaleReturnService::MAX_PER_PAGE),
        ];
    }

    /**
     * @return array{search: string, sale_id: int|null, status: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'sale_id' => $this->filled('sale_id') ? (int) $this->input('sale_id') : null,
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', SaleReturnService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', SaleReturnService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(SaleReturnService::DEFAULT_PER_PAGE),
        ];
    }
}
