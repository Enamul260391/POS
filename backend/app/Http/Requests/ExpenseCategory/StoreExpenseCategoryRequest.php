<?php

declare(strict_types=1);

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreExpenseCategoryRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', $this->uniqueNameRule()],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::in(ExpenseCategory::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ]);
    }

    private function uniqueNameRule(): Unique
    {
        return Rule::unique('expense_categories', 'name')->where('business_id', $this->businessId());
    }
}
