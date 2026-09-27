<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Services\Purchase\PurchaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query string of `GET /api/v1/purchases`.
 *
 * Every filter is applied in SQL by {@see PurchaseService::paginate()} —
 * supplier, status, payment status, purchase-date range and search — so
 * nothing is loaded and filtered in PHP, and `sort` is restricted to the
 * columns the service is willing to order by.
 *
 * A `supplier_id` from another business simply matches nothing: the business
 * scope is applied first, so the response never reveals whether it exists
 * elsewhere.
 */
class IndexPurchaseRequest extends FormRequest
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
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:20'],
            'payment_status' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            ...$this->orderingRules(PurchaseService::SORTABLE_COLUMNS),
            ...$this->paginationRules(PurchaseService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see PurchaseService::paginate()}.
     *
     * @return array{search: string, supplier_id: int|null, status: string|null, payment_status: string|null, date_from: string|null, date_to: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'supplier_id' => $this->filled('supplier_id') ? (int) $this->input('supplier_id') : null,
            'status' => $this->input('status'),
            'payment_status' => $this->input('payment_status'),
            'date_from' => $this->filled('date_from')
                ? Carbon::parse($this->input('date_from'))->toDateString()
                : null,
            'date_to' => $this->filled('date_to')
                ? Carbon::parse($this->input('date_to'))->toDateString()
                : null,
            'sort' => (string) $this->input('sort', PurchaseService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', PurchaseService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(PurchaseService::DEFAULT_PER_PAGE),
        ];
    }
}