<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Expense;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('business_id', $this->businessId()))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['nullable', 'string', Rule::in(Expense::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => $this->filled('reference') ? trim((string) $this->input('reference')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ]);
    }
}
