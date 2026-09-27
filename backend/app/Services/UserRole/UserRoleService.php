<?php

declare(strict_types=1);

namespace App\Services\UserRole;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns roles to users, always inside a single business.
 *
 * Two ownership rules are enforced here, and neither trusts the request body:
 *
 *  1. the **target user** must belong to the authenticated user's business, and
 *  2. every **role** being granted must belong to that same business.
 *
 * Together they make cross-tenant privilege escalation impossible: a Business A
 * administrator can neither reach a Business B account nor attach a Business B
 * role to their own users.
 */
final class UserRoleService
{
    /**
     * Resolve a user inside the given business, or 404.
     *
     * The lookup is business-scoped rather than "fetch then compare", so a user
     * of another tenant is reported as missing without ever being loaded.
     *
     * @throws ModelNotFoundException
     */
    public function findUser(int $businessId, int $userId): User
    {
        return User::query()
            ->where('business_id', $businessId)
            ->whereKey($userId)
            ->firstOrFail();
    }

    /**
     * Replace the roles held by a user.
     *
     * Validates the whole role set before writing anything, then performs the
     * `sync()` inside a transaction with its permission eager-load. Sending an
     * empty list intentionally detaches every role.
     *
     * @param  array<int, int>  $roleIds
     *
     * @throws ValidationException when a role does not exist or belongs to
     *                             another business.
     */
    public function syncRoles(int $businessId, User $user, array $roleIds): User
    {
        $roleIds = $this->assertRolesBelongToBusiness($businessId, $roleIds);

        DB::transaction(function () use ($user, $roleIds): void {
            // `role_user` has a unique `(user_id, role_id)` index, so `sync()`
            // cannot create duplicate pivot rows.
            $user->roles()->sync($roleIds);
        });

        return $this->findUser($businessId, (int) $user->getKey())->load('roles.permissions');
    }

    /**
     * Validate that every requested role belongs to the business.
     *
     * Roles are resolved in a single query and compared against the requested
     * set, so N ids cost one round trip. Ids that do not exist and ids owned by
     * another business are both reported as invalid — the message never
     * distinguishes the two, which avoids confirming the existence of another
     * tenant's role.
     *
     * @param  array<int, int>  $roleIds
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function assertRolesBelongToBusiness(int $businessId, array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roleIds = array_values(array_filter($roleIds, static fn (int $id): bool => $id > 0));

        if ($roleIds === []) {
            return [];
        }

        $owned = Role::query()
            ->forBusiness($businessId)
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $invalid = array_values(array_diff($roleIds, $owned));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'role_ids' => [
                    'The following role ids are invalid: '.implode(', ', $invalid).'.',
                ],
            ]);
        }

        return $owned;
    }
}
