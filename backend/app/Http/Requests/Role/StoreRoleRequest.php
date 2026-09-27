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
 * Payload of `POST /api/v1/roles`.
 *
 * `business_id` is intentionally absent from the rules: ownership always comes
 * from the authenticated user, so sending one has no effect. `slug` is derived
 * from `name` by the service instead of being accepted, which keeps the
 * `(business_id, slug)` unique index satisfiable and the identifier canonical.
 *
 * `permission_ids` is validated for existence here (permissions are a global
 * catalogue) and re-checked inside the service transaction, so an unknown id
 * fails with 422 before any row is written.
 */
class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:'.Role::MAX_NAME_LENGTH, $this->uniqueNameRule()],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_ids' => ['sometimes', 'array'],
            // `distinct` rejects a repeated id in the same payload, which would
            // otherwise be a no-op in the pivot table.
            'permission_ids.*' => ['integer', 'distinct', Rule::exists(Permission::class, 'id')],
        ];
    }

    /**
     * Trim incoming strings so " Manager " and "Manager" cannot both exist.
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
     * Display names must stay unique inside the authenticated business so the
     * role list stays unambiguous; the slug is derived from the name, and the
     * database additionally enforces `(business_id, slug)`.
     */
    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('roles', 'name')->where('business_id', $this->businessId());
    }
}
