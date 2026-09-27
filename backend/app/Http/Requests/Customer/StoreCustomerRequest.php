<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/customers`.
 *
 * Mirrors the actual `customers` table (Phase 16): `name`, optional `phone`,
 * `email`, `address`, `opening_balance` and `status`. There is no unique
 * index on phone/email/name in the schema, so no uniqueness is invented
 * here — duplicate contacts are allowed exactly as the database allows them.
 *
 * `business_id` is intentionally absent — the tenant comes from the
 * authenticated user.
 */
class StoreCustomerRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(Customer::STATUSES)],
        ];
    }

    /**
     * Trim strings and normalise blank optional values to `null`, so the API
     * treats an empty form field as "not set" instead of storing `''`.
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