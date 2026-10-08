<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\BusinessUser;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PUT|PATCH .../users/{user}/roles`.
 *
 * Full-replace (sync) semantics, mirroring the tenant role endpoint: an
 * explicit `[]` detaches every role. Ids are validated against the route
 * business, so a role of another tenant is rejected as invalid input.
 * `business_id` is not part of the contract: tenancy comes from the route.
 */
class SyncBusinessUserRolesRequest extends FormRequest
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
            // `present` + `array` (not `required`) so an explicit `[]` is a
            // valid "remove every role" request rather than a missing field.
            'role_ids' => ['present', 'array'],
            'role_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Role::class, 'id')->where('business_id', (int) $this->route('business')),
            ],
        ];
    }

    /**
     * Normalised id list: integers, deduplicated, order preserved.
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
