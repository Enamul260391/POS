<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Services\Sale\SaleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query string of `GET /api/v1/sales`.
 */
class IndexSaleRequest extends FormRequest
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
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:20'],
            'payment_status' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            ...$this->orderingRules(SaleService::SORTABLE_COLUMNS),
            ...$this->paginationRules(SaleService::MAX_PER_PAGE),
        ];
    }

    /**
     * @return array{search: string, customer_id: int|null, status: string|null, payment_status: string|null, date_from: string|null, date_to: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
            'status' => $this->input('status'),
            'payment_status' => $this->input('payment_status'),
            'date_from' => $this->filled('date_from') ? Carbon::parse($this->input('date_from'))->toDateString() : null,
            'date_to' => $this->filled('date_to') ? Carbon::parse($this->input('date_to'))->toDateString() : null,
            'sort' => (string) $this->input('sort', SaleService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', SaleService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(SaleService::DEFAULT_PER_PAGE),
        ];
    }
}
