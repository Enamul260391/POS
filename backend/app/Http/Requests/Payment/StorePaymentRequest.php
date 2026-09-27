<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/sales/{sale}/payments`.
 */
class StorePaymentRequest extends FormRequest
{
    use ResolvesBusiness;

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
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'payment_method' => ['required', 'string', Rule::in(Payment::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => $this->filled('reference') ? trim((string) $this->input('reference')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
