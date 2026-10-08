<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Business;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PATCH /api/v1/platform/businesses/{business}/status`.
 *
 * The only writer of the `status` column on the platform API: the update
 * endpoint deliberately accepts no `status`, so activation state changes go
 * through exactly this contract and the existing `active`/`inactive` model is
 * never shadowed by a second status system.
 */
class UpdateBusinessStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(Business::STATUSES)],
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
