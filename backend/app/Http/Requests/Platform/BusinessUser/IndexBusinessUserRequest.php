<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\BusinessUser;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/platform/businesses/{business}/users`.
 *
 * Mirrors the tenant staff-directory listing (search, status/role filters,
 * sorting, pagination). Tenancy comes from the `{business}` route segment
 * — resolved by the controller through PlatformUserService — never from
 * the query string.
 */
class IndexBusinessUserRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'role_id' => ['nullable', 'integer', 'min:1'],
            ...$this->orderingRules(UserService::SORTABLE_COLUMNS),
            ...$this->paginationRules(UserService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised filters for {@see \App\Services\Platform\PlatformUserService::paginate()}.
     *
     * @return array{search: string, status: string|null, role_id: int|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        $roleId = $this->input('role_id');

        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'role_id' => $roleId === null ? null : (int) $roleId,
            'sort' => (string) $this->input('sort', UserService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', UserService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(UserService::DEFAULT_PER_PAGE),
        ];
    }
}
