<?php

declare(strict_types=1);

namespace App\Http\Requests\UserRole;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PUT|PATCH /api/v1/users/{user}/roles`.
 *
 * The role ids are validated against the **authenticated user's** business
 * (`Rule::exists(...)->where('business_id', ...)`), so a role from another
 * tenant is rejected as invalid input instead of being silently accepted.
 * `business_id` is not part of the rules: tenancy always comes from the token.
 */
class SyncUserRolesRequest extends FormRequest
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
            // `present` + `array` (not `required`) so an explicit `[]` is a
            // valid "remove every role" request rather than a missing field.
            'role_ids' => ['present', 'array'],
            'role_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Role::class, 'id')->where('business_id', $this->businessId()),
            ],
        ];
    }

    /**
     * Normalise the id list: strings become integers, duplicates collapse, and
     * the (already `distinct`-validated) order is preserved.
     *
     * @return array<int, int>
     */
    public function roleIds(): array
    {
        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            (array) $this->input('role_ids', []),
        )));
    }
}
