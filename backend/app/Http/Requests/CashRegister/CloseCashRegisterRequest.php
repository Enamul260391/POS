<?php

declare(strict_types=1);

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashRegisterRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'closing_balance' => ['required', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('notes')) {
            $this->merge(['notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null]);
        }
    }
}
