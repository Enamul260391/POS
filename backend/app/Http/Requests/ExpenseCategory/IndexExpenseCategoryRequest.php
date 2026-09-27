<?php

declare(strict_types=1);

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategory\ExpenseCategoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExpenseCategoryRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(ExpenseCategory::STATUSES)],
            ...$this->orderingRules(ExpenseCategoryService::SORTABLE_COLUMNS),
            ...$this->paginationRules(ExpenseCategoryService::MAX_PER_PAGE),
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'status' => $this->input('status'),
            'sort' => (string) $this->input('sort', ExpenseCategoryService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', ExpenseCategoryService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(ExpenseCategoryService::DEFAULT_PER_PAGE),
        ];
    }
}
