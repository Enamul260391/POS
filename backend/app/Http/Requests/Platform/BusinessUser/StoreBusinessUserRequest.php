<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\BusinessUser;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Payload of `POST /api/v1/platform/businesses/{business}/users`.
 *
 * Mirrors the tenant `StoreUserRequest` (same profile fields, global email
 * uniqueness, min-8 password, role owned by the route business), with two
 * platform additions: `business_id`, `platform_role` and
 * `is_platform_owner` are prohibited inputs, so the client can never
 * override the route business or mint a second Platform Owner.
 */
class StoreBusinessUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'notes' => ['nullable', 'string', 'max:500'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists(Role::class, 'id')->where('business_id', (int) $this->route('business')),
            ],
            'password' => ['required', 'string', Password::min(8)],
            'business_id' => ['prohibited'],
            'platform_role' => ['prohibited'],
            'is_platform_owner' => ['prohibited'],
        ];
    }

    /**
     * Trim strings and lower-case the email (the column is unique).
     */
    protected function prepareForValidation(): void
    {
        $email = trim((string) $this->input('email'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => $email === '' ? $email : strtolower($email),
            'phone' => $this->nullableString('phone'),
            'branch' => $this->nullableString('branch'),
            'notes' => $this->nullableString('notes'),
        ]);
    }

    /**
     * Trimmed value, or `null` when absent/blank.
     */
    protected function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
