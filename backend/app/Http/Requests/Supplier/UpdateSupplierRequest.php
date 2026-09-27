<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PUT|PATCH /api/v1/suppliers/{supplier}`.
 *
 * Master data only. `business_id` is never validated (the tenant cannot
 * change) and `id` is not part of the contract, so neither can be altered.
 * Every field uses `sometimes` so PATCH can send a partial document while a
 * full PUT simply re-sends everything.
 */
class UpdateSupplierRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'opening_balance' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:'.Money::MAX, 'decimal:0,2'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Supplier::STATUSES)],
        ];
    }

    /**
     * Trim provided strings and normalise blank optional values to `null`.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'status'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => trim((string) $this->input($key))]);
            }
        }

        foreach (['phone', 'email', 'address'] as $key) {
            if ($this->has($key)) {
                $this->merge([
                    $key => $this->filled($key) ? trim((string) $this->input($key)) : null,
                ]);
            }
        }
    }
}