<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of `PUT|PATCH /api/v1/platform/businesses/{business}`.
 *
 * Master data only. `id` is the primary key and is never part of the
 * contract; `status` has its own dedicated endpoint
 * (`PATCH .../{business}/status`) so there is exactly one status system; and
 * `settings` is tenant-managed through `/settings`, never written as a raw
 * platform payload. Every field uses `sometimes` so PATCH can send a partial
 * document while a full PUT simply re-sends everything.
 */
class UpdateBusinessRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50', 'timezone'],
        ];
    }

    /**
     * Trim provided strings and normalise email/currency casing, mirroring
     * {@see StoreBusinessRequest} so create and update accept the same shapes.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        foreach (['phone', 'address', 'logo', 'timezone'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $this->merge([
                    $key => is_string($value) && trim($value) !== '' ? trim($value) : null,
                ]);
            }
        }

        if ($this->has('email')) {
            $email = trim((string) $this->input('email'));
            $this->merge(['email' => $email === '' ? null : strtolower($email)]);
        }

        if ($this->has('currency')) {
            $currency = trim((string) $this->input('currency'));
            $this->merge(['currency' => $currency === '' ? null : strtoupper($currency)]);
        }
    }
}
