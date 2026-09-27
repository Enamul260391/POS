<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/suppliers`.
 *
 * Mirrors the actual `suppliers` table (Phase 16): `name`, optional `phone`,
 * `email`, `address`, `opening_balance` and `status`. The schema carries no
 * unique index on phone/email/name, so no uniqueness is invented here.
 *
 * `business_id` is intentionally absent — the tenant comes from the
 * authenticated user.
 */
class StoreSupplierRequest extends FormRequest
{
    use ResolvesBusiness;

    /**
     * Determine if the user is authorized to make this request.
     */
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
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'status' => ['nullable', 'string', Rule::in(Supplier::STATUSES)],
        ];
    }

    /**
     * Trim strings and normalise blank optional values to `null`.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : null,
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
        ]);
    }
}