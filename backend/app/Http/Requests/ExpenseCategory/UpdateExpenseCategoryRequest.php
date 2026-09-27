<?php

declare(strict_types=1);

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateExpenseCategoryRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', $this->uniqueNameRule()],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(ExpenseCategory::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
        if ($this->has('description')) {
            $this->merge(['description' => $this->filled('description') ? trim((string) $this->input('description')) : null]);
        }
    }

    private function uniqueNameRule(): Unique
    {
        return Rule::unique('expense_categories', 'name')
            ->where('business_id', $this->businessId())
            ->ignore((int) $this->route('expenseCategory'));
    }
}
