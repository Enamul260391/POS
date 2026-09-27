<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Supplier;
use App\Services\Supplier\SupplierService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/suppliers`.
 *
 * Every filter is applied in SQL by {@see SupplierService::paginate()}; nothing
 * is loaded and filtered in PHP, and `sort` is restricted to the columns the
 * service is willing to order by.
 */
class IndexSupplierRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(Supplier::STATUSES)],
            ...$this->orderingRules(SupplierService::SORTABLE_COLUMNS),
            ...$this->paginationRules(SupplierService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see SupplierService::paginate()}.
     *
     * @return array{search: string, status: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', SupplierService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', SupplierService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(SupplierService::DEFAULT_PER_PAGE),
        ];
    }
}