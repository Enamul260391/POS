<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\BusinessUser;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `PUT|PATCH /api/v1/platform/businesses/{business}/users/{user}`.
 *
 * Mirrors the tenant `UpdateUserRequest`: every field optional, unique
 * email ignores the row being edited, role is validated against the route
 * business. `status` is intentionally absent — it has its own endpoint
 * (`PATCH .../status`). `id`, `business_id`, `platform_role` and
 * `is_platform_owner` are prohibited, so profile edits can never move the
 * user or mint platform identity.
 */
class UpdateBusinessUserRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:254', $this->uniqueEmailRule()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists(Role::class, 'id')->where('business_id', (int) $this->route('business')),
            ],
            // Blank means "keep the current password" — the service ignores it.
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)],
            'id' => ['prohibited'],
            'business_id' => ['prohibited'],
            'platform_role' => ['prohibited'],
            'is_platform_owner' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * Trim provided strings and lower-case the email.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'phone', 'branch', 'notes'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = $key === 'name'
                    ? trim((string) $this->input($key))
                    : $this->nullableString($key);
            }
        }

        if ($this->has('email')) {
            $email = trim((string) $this->input('email'));
            $merge['email'] = $email === '' ? $email : strtolower($email);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Email uniqueness across the table, ignoring the row being edited.
     */
    protected function uniqueEmailRule(): Unique
    {
        return Rule::unique('users', 'email')->ignore($this->route('user'));
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
