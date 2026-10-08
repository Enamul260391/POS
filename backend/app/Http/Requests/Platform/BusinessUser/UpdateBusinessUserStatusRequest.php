<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\BusinessUser;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PATCH .../users/{user}/status`.
 *
 * The only writer of `status` on the platform user API: the update
 * endpoint prohibits `status`, so activation state changes go through
 * exactly this contract and the existing active/inactive model is never
 * shadowed by a second status system.
 */
class UpdateBusinessUserStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ];
    }

    /**
     * Trim the incoming value so `" inactive "` cannot fail validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            $this->merge(['status' => trim((string) $this->input('status'))]);
        }
    }
}
