<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Expense;
use App\Services\Expense\ExpenseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExpenseRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'expense_category_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', Rule::in(Expense::PAYMENT_METHODS)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ...$this->orderingRules(ExpenseService::SORTABLE_COLUMNS),
            ...$this->paginationRules(ExpenseService::MAX_PER_PAGE),
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search')),
            'expense_category_id' => $this->filled('expense_category_id') ? (int) $this->input('expense_category_id') : null,
            'payment_method' => $this->input('payment_method'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'sort' => (string) $this->input('sort', ExpenseService::DEFAULT_SORT_COLUMN),
            'direction' => (string) $this->input('direction', ExpenseService::DEFAULT_SORT_DIRECTION),
            'per_page' => $this->perPage(ExpenseService::DEFAULT_PER_PAGE),
        ];
    }
}
