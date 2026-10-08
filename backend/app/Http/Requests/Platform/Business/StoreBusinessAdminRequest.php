<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Business;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Payload of `POST /api/v1/platform/businesses/{business}/admin`.
 *
 * Provisions the first Business Admin for a newly created business.
 * The request mirrors the tenant `StoreUserRequest` but with platform-specific
 * guards: the target business is resolved from the route (not the payload),
 * the admin role is determined server-side, and platform identity fields are
 * prohibited so a second Platform Owner can never be minted.
 */
class StoreBusinessAdminRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string', Password::min(8)],
            'password_confirmation' => ['required', 'string', 'same:password'],
            'business_id' => ['prohibited'],
            'platform_role' => ['prohibited'],
            'is_platform_owner' => ['prohibited'],
            'role_id' => ['prohibited'],
            'status' => ['prohibited'],
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

    /**
     * Returns only the fields intended for user creation.
     *
     * Excludes `password_confirmation` and all prohibited fields.
     *
     * @return array<string, mixed>
     */
    public function userData(): array
    {
        return [
            'name' => $this->validated()['name'],
            'email' => $this->validated()['email'],
            'phone' => $this->validated()['phone'],
            'branch' => $this->validated()['branch'],
            'notes' => $this->validated()['notes'],
            'password' => $this->validated()['password'],
            'status' => User::STATUS_ACTIVE,
        ];
    }
}