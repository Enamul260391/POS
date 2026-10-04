<?php

declare(strict_types=1);

namespace App\Http\Requests\UserPermission;

use App\Models\Permission;
use App\Models\UserPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/users/{user}/permission-overrides`.
 *
 * `permission_id` is validated for existence in the **global** catalogue (the
 * `permissions` table has no `business_id`, so there is no ownership to check),
 * and re-checked inside the service before anything is written. `effect` is
 * restricted to the two states the model declares.
 *
 * `business_id` is intentionally absent from the rules: the owning tenant always
 * comes from the authenticated user, so sending one has no effect.
 */
class StoreUserPermissionOverrideRequest extends FormRequest
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
            'permission_id' => ['required', 'integer', Rule::exists(Permission::class, 'id')],
            'effect' => ['required', 'string', Rule::in(UserPermission::EFFECTS)],
        ];
    }

    /**
     * The validated permission id.
     */
    public function permissionId(): int
    {
        return (int) $this->validated()['permission_id'];
    }

    /**
     * The validated effect — always `allow` or `deny`.
     */
    public function effect(): string
    {
        return (string) $this->validated()['effect'];
    }
}
