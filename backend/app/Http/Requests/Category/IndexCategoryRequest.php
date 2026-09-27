<?php

declare(strict_types=1);

namespace App\Http\Requests\Category;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Category;
use App\Services\Category\CategoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/categories`.
 */
class IndexCategoryRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(Category::STATUSES)],
            ...$this->orderingRules(CategoryService::SORTABLE_COLUMNS),
            ...$this->paginationRules(CategoryService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised, whitelisted and defaulted filters for
     * {@see CategoryService::paginate()}.
     *
     * @return array{search: string, status: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', CategoryService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', CategoryService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(CategoryService::DEFAULT_PER_PAGE),
        ];
    }
}
