<?php

declare(strict_types=1);

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\CashRegisterTransaction;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashRegisterTransactionRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(CashRegisterTransaction::MANUAL_TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $this->merge(['note' => $this->filled('note') ? trim((string) $this->input('note')) : null]);
        }
    }
}
