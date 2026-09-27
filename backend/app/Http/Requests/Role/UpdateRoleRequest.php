<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `PUT|PATCH /api/v1/roles/{role}`.
 *
 * Fields are `sometimes`, so both verbs behave like a partial update and a
 * client may send only what changed. In particular, omitting `permission_ids`
 * leaves the existing grants untouched, while sending `[]` removes them all —
 * the distinction is the reason the field is not simply `required`.
 *
 * `business_id` and `id` are never validated, so they cannot be changed even if
 * a client sends them.
 */
class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:'.Role::MAX_NAME_LENGTH, $this->uniqueNameRule()],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists(Permission::class, 'id')],
        ];
    }

    /**
     * Trim the incoming strings and normalise an empty description to `null`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        if ($this->has('description')) {
            $this->merge([
                'description' => $this->filled('description')
                    ? trim((string) $this->input('description'))
                    : null,
            ]);
        }
    }

    /**
     * Name uniqueness inside the authenticated business, ignoring the role
     * being updated so it may keep its own name.
     */
    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('roles', 'name')
            ->where('business_id', $this->businessId())
            ->ignore((int) $this->route('role'));
    }
}
