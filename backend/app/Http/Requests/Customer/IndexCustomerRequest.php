<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/customers`.
 *
 * Every filter is applied in SQL by {@see CustomerService::paginate()}; nothing
 * is loaded and filtered in PHP, and `sort` is restricted to the columns the
 * service is willing to order by.
 */
class IndexCustomerRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(Customer::STATUSES)],
            ...$this->orderingRules(CustomerService::SORTABLE_COLUMNS),
            ...$this->paginationRules(CustomerService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see CustomerService::paginate()}.
     *
     * @return array{search: string, status: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', CustomerService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', CustomerService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(CustomerService::DEFAULT_PER_PAGE),
        ];
    }
}