<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Business;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/platform/businesses`.
 *
 * Mirrors the actual `businesses` table: `name` plus the optional contact,
 * locale and status columns. The schema carries no unique index on
 * name/email/phone, so — following the `StoreSupplierRequest` precedent — no
 * uniqueness is invented here.
 *
 * The request creates the tenant only: there is no user, role or owner
 * assignment in this contract, so the Platform Owner can never be attached
 * to the new business through it.
 */
class StoreBusinessRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `platform` + `can:` route middleware.
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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:50', 'timezone'],
            'status' => ['nullable', 'string', Rule::in(Business::STATUSES)],
        ];
    }

    /**
     * Trim strings, normalise blank optional values to `null`, and normalise
     * email/currency casing so equivalent input cannot produce duplicate
     * rows.
     */
    protected function prepareForValidation(): void
    {
        $email = trim((string) $this->input('email'));
        $currency = trim((string) $this->input('currency'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => $this->nullableString('phone'),
            'email' => $email === '' ? null : strtolower($email),
            'address' => $this->nullableString('address'),
            'logo' => $this->nullableString('logo'),
            'currency' => $currency === '' ? null : strtoupper($currency),
            'timezone' => $this->nullableString('timezone'),
        ]);
    }

    /**
     * Trimmed value, or `null` when absent/blank — the shape the columns expect.
     */
    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
