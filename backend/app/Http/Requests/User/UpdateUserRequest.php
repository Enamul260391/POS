<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `PUT|PATCH /api/v1/users/{user}`.
 *
 * Every field is optional so a status-only edit does not have to resend the
 * whole record, but the unique email rule ignores the row being edited.
 * `role_id` is validated against the authenticated user's business, exactly as
 * on create.
 */
class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:254', $this->uniqueEmailRule()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists(Role::class, 'id')->where('business_id', $this->businessId()),
            ],
            // Blank means "keep the current password" — the service ignores it.
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)],
        ];
    }

    /**
     * Trim the incoming strings and lower-case the email, so a re-save of the
     * same record cannot fail on whitespace alone.
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