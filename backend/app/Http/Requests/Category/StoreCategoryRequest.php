<?php

declare(strict_types=1);

namespace App\Http\Requests\Category;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `POST /api/v1/categories`.
 *
 * `business_id` is intentionally absent: the owning business always comes from
 * the authenticated user, so a client cannot place a category in another
 * tenant. `slug` is derived from the name by the service instead of being
 * accepted, which keeps it valid and unique-format by construction.
 */
class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', $this->uniqueNameRule()],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', Rule::in(Category::STATUSES)],
        ];
    }

    /**
     * Trim the incoming strings so " Drinks " and "Drinks" cannot both exist.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }

    /**
     * Name uniqueness inside the authenticated business.
     *
     * Soft-deleted rows are included on purpose: `categories` carries a
     * `(business_id, name)` unique index, so a trashed name is still taken and
     * must produce a validation error instead of a database exception.
     */
    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('categories', 'name')->where('business_id', $this->businessId());
    }
}
