<?php

declare(strict_types=1);

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\CashRegisterTransaction;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend an already-recorded manual cash in / cash out.
 *
 * Deliberately the same three fields `CashRegisterTransactionRequest` accepts
 * for the create, because those are exactly the columns the manual endpoint
 * ever writes: `type`, `amount` and `note`. Everything else on the row —
 * `business_id`, `cash_register_id`, `reference_type`, `reference_id` and
 * `created_by` — is resolved from stored state or from the authenticated user
 * and is not accepted here, so a request can neither move a transaction to
 * another tenant or drawer nor attach it to a sale, an expense or a payment.
 *
 * `type` is still constrained to `MANUAL_TYPES`: an amendment may flip a cash
 * in to a cash out, but it can never turn a manual movement into the automatic
 * `sale` / `expense` kinds, whose real meaning lives in the document they
 * reference. That is also why the fields are `sometimes` — a caller may amend
 * the note alone without having to restate the amount.
 */
class UpdateCashRegisterTransactionRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', 'string', Rule::in(CashRegisterTransaction::MANUAL_TYPES)],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $this->merge(['note' => $this->filled('note') ? trim((string) $this->input('note')) : null]);
        }
    }
}