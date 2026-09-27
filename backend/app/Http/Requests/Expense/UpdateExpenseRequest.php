<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Expense;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('business_id', $this->businessId()))],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'expense_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'payment_method' => ['sometimes', 'required', 'string', Rule::in(Expense::PAYMENT_METHODS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reference', 'description'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->filled($field) ? trim((string) $this->input($field)) : null]);
            }
        }
    }
}
