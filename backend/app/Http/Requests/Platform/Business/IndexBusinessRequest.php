<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Business;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Models\Business;
use App\Services\Platform\BusinessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/platform/businesses`.
 *
 * Every filter is applied in SQL by {@see BusinessService::paginate()};
 * nothing is loaded and filtered in PHP, and `sort` is restricted to the
 * columns the service is willing to order by.
 *
 * Intentionally tenant-free: unlike the tenant list requests this one does
 * not resolve a `business_id` from the caller — the Platform Owner has none
 * and reads across businesses by design.
 */
class IndexBusinessRequest extends FormRequest
{
    use HandlesListQuery;

    /**
     * Authorization is enforced by the `platform` + `can:` route middleware.
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
            'status' => ['nullable', 'string', Rule::in(Business::STATUSES)],
            ...$this->orderingRules(BusinessService::SORTABLE_COLUMNS),
            ...$this->paginationRules(BusinessService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see BusinessService::paginate()}.
     *
     * @return array{search: string, status: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', BusinessService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', BusinessService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(BusinessService::DEFAULT_PER_PAGE),
        ];
    }
}
