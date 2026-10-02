<?php

declare(strict_types=1);

namespace App\Http\Requests\UserPermission;

use App\Models\Permission;
use App\Models\UserPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `PUT|PATCH /api/v1/users/{user}/permission-overrides`.
 *
 * The list is the *complete* desired override set: anything absent is removed,
 * anything present is created or updated. An explicit `[]` is therefore a valid
 * "remove every override" request, which is why the field is `present` rather
 * than `required`.
 *
 * `distinct` on `permission_id` is what makes the set unambiguous. The unique
 * `(business_id, user_id, permission_id)` index would otherwise reject the
 * second row with a database error, and silently letting the last one win would
 * make the caller's intent unknowable — so a repeated permission is reported as
 * invalid input naming the offending index.
 *
 * `business_id` is intentionally absent: the tenant always comes from the token.
 */
class SyncUserPermissionOverridesRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:` route middleware, plus the
     * self-management check inside `UserPermissionService`.
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
            'overrides' => ['present', 'array'],
            'overrides.*' => ['array'],
            'overrides.*.permission_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Permission::class, 'id'),
            ],
            'overrides.*.effect' => ['required', 'string', Rule::in(UserPermission::EFFECTS)],
        ];
    }

    /**
     * Messages that name the repeated permission rather than only the field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overrides.*.permission_id.distinct' => 'This permission is listed more than once; each permission may appear only once.',
        ];
    }

    /**
     * The validated override list, in the submitted order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overrides(): array
    {
        return array_values((array) $this->validated()['overrides']);
    }
}
