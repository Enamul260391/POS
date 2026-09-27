<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Services\Role\RoleService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of `GET /api/v1/roles`.
 */
class IndexRoleRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

    /**
     * Authorization is enforced by the `can:` route middleware; this request
     * only owns input validation.
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
            ...$this->orderingRules(RoleService::SORTABLE_COLUMNS),
            ...$this->paginationRules(RoleService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see RoleService::paginate()}.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'sort' => (string) $this->input('sort', RoleService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', RoleService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(RoleService::DEFAULT_PER_PAGE),
        ];
    }
}
