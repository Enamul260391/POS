<?php

declare(strict_types=1);

namespace App\Http\Requests\Permission;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of `GET /api/v1/permissions`.
 *
 * The permission catalogue is global, so there is no tenant filter to expose.
 */
class IndexPermissionRequest extends FormRequest
{
    use HandlesListQuery;

    /**
     * Authorization is enforced by the `can:` route middleware.
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
            ...$this->orderingRules(PermissionService::SORTABLE_COLUMNS),
            ...$this->paginationRules(PermissionService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see PermissionService::paginate()}.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'sort' => (string) $this->input('sort', PermissionService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', PermissionService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(PermissionService::DEFAULT_PER_PAGE),
        ];
    }
}
