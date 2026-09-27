<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Payload of `POST /api/v1/users`.
 *
 * `business_id` is intentionally absent: the owning business always comes from
 * the authenticated user, so a client cannot place an account in another
 * tenant. `role_id` is validated against the **authenticated user's** business
 * so a role of another tenant is rejected as invalid input rather than being
 * silently attached.
 */
class StoreUserRequest extends FormRequest
{
    use ResolvesBusiness;

    /**
     * Authorization is enforced by the `can:` route middleware.
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
            'email' => ['required', 'string', 'email', 'max:254', $this->uniqueEmailRule()],
            'phone' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'notes' => ['nullable', 'string', 'max:500'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists(Role::class, 'id')->where('business_id', $this->businessId()),
            ],
            'password' => ['required', 'string', Password::min(8)],
        ];
    }

    /**
     * Trim the incoming strings so " Cashier " and "Cashier" cannot both exist,
     * and normalise the email to lower case (the column is unique).
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
     * Email uniqueness across the whole table.
     *
     * `users.email` carries a global unique index, so a duplicate has to be
     * reported as a validation error rather than a database exception.
     */
    protected function uniqueEmailRule(): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('users', 'email');
    }

    /**
     * Trimmed value, or `null` when absent/blank — the shape the columns expect.
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