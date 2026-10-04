<?php

declare(strict_types=1);

namespace App\Services\UserPermission;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages the user-specific permission overrides of a single business.
 *
 * This is the write side of the authorisation model produced by
 * `User::effectivePermissionSlugs()` (STEP 3). It only ever touches
 * `user_permissions` — role ↔ permission grants (`permission_role`) and role
 * assignment (`role_user`) stay with `RoleService` / `UserRoleService` and are
 * never read or written here.
 *
 * Three ownership rules are enforced, none of which trusts the request body:
 *
 *  1. the **target user** must belong to the authenticated user's business,
 *  2. the **actor** and the target must be different people, and
 *  3. every **permission** must exist in the global catalogue.
 *
 * Together they close the two obvious escalation paths: a Business A
 * administrator can neither reach a Business B account, nor use an override to
 * grant *themselves* a capability they do not already hold. There is deliberately
 * no owner/super-admin exception — a bypass for one account would be a bypass the
 * next tenant can claim too.
 */
final class UserPermissionService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Resolve a user inside the given business, or 404.
     *
     * The lookup is business-scoped rather than "fetch then compare", so a user
     * of another tenant is reported as missing without ever being loaded — the
     * response cannot confirm that the account exists.
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
     * May `$actor` administer the overrides of `$user`?
     *
     * Guards both the read and the write entry points, so the rule cannot be
     * bypassed by simply calling the list endpoint.
     *
     * @throws AuthorizationException when the actor is the target.
     * @throws ModelNotFoundException when the two are not in the same business.
     */
    public function assertMayManage(User $actor, User $user): void
    {
        if ((int) $actor->business_id !== (int) $user->business_id) {
            // Reported as "not found" rather than "forbidden": the caller must not
            // learn that the account exists in a business that is not theirs.
            throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
        }

        if ((int) $actor->getKey() === (int) $user->getKey()) {
            // A user may not manage their own overrides. An ALLOW row would hand
            // them a capability the API has just proved they do not hold, and a
            // DENY row is not needed to lock yourself out. The `can:` middleware
            // has already evaluated the actor's *effective* permissions at this
            // point, so refusing here can never widen them.
            throw new AuthorizationException('You cannot manage your own permission overrides.');
        }
    }

    /**
     * The user's stored overrides, inside their own business.
     *
     * Rows whose `business_id` differs are ignored exactly as
     * `User::tenantPermissionOverrides()` ignores them during authorisation, so
     * the management screen and the resolver can never disagree about what the
     * user holds.
     *
     * @return Collection<int, UserPermission>
     */
    public function overridesFor(User $user): Collection
    {
        return $user->permissionOverrides()
            ->where('business_id', $user->business_id)
            ->with('permission')
            ->orderBy('permission_id')
            ->get();
    }

    /**
     * Create or update one override (`user` + `permission`).
     *
     * The `(business_id, user_id, permission_id)` unique index makes a second
     * row impossible, so the write is an `updateOrCreate()` scoped to the caller's
     * business: a repeat request flips the existing effect instead of inserting a
     * duplicate. A forged cross-business row is a *different* key for that index
     * and is therefore neither found nor modified.
     *
     * @return Collection<int, UserPermission> The full override set after the write.
     */
    public function upsert(int $businessId, User $actor, User $user, int $permissionId, string $effect): Collection
    {
        $this->assertMayManage($actor, $user);
        $this->assertPermissionsExist([$permissionId]);

        DB::transaction(function () use ($businessId, $user, $permissionId, $effect): void {
            UserPermission::updateOrCreate(
                [
                    'business_id' => $businessId,
                    'user_id' => (int) $user->getKey(),
                    'permission_id' => $permissionId,
                ],
                ['effect' => $effect],
            );
        });

        return $this->afterWrite($user);
    }

    /**
     * Remove one override (`user` + `permission`).
     *
     * The delete is scoped by `business_id` as well as `user_id`, so an override
     * row belonging to another tenant can never be removed through this API even
     * when its `user_id` matches.
     *
     * @throws ModelNotFoundException when the user has no such override.
     */
    public function delete(int $businessId, User $actor, User $user, int $permissionId): void
    {
        $this->assertMayManage($actor, $user);

        DB::transaction(function () use ($businessId, $user, $permissionId): void {
            $deleted = UserPermission::query()
                ->where('business_id', $businessId)
                ->where('user_id', (int) $user->getKey())
                ->where('permission_id', $permissionId)
                ->delete();

            if ($deleted === 0) {
                // Nothing to remove for this user: an absent override, a foreign
                // permission id and a row owned by another tenant are all
                // indistinguishable from the outside.
                throw (new ModelNotFoundException)->setModel(UserPermission::class, [$permissionId]);
            }
        });

        $this->afterWrite($user);
    }

    /**
     * Replace the user's complete override set.
     *
     * Reconciliation, not blind delete/recreate: rows the payload omits are
     * deleted, rows it repeats are updated, rows it adds are inserted. Every
     * statement runs inside one transaction, so a failure anywhere leaves the
     * previous set exactly as it was — a user can never be left half
     * re-authorised. Permission ids are validated *before* the transaction opens,
     * so an unknown id costs no write at all.
     *
     * @param  array<int, array<string, mixed>>  $overrides
     * @return Collection<int, UserPermission> The stored set after the write.
     *
     * @throws ValidationException on an unknown or repeated permission id.
     */
    public function sync(int $businessId, User $actor, User $user, array $overrides): Collection
    {
        $this->assertMayManage($actor, $user);

        $desired = $this->normalise($overrides);

        $this->assertPermissionsExist(array_keys($desired));

        $userId = (int) $user->getKey();

        DB::transaction(function () use ($businessId, $userId, $desired): void {
            $stale = UserPermission::query()
                ->where('business_id', $businessId)
                ->where('user_id', $userId);

            if ($desired === []) {
                $stale->delete();
            } else {
                $stale->whereNotIn('permission_id', array_keys($desired))->delete();
            }

            foreach ($desired as $permissionId => $effect) {
                UserPermission::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'user_id' => $userId,
                        'permission_id' => $permissionId,
                    ],
                    ['effect' => $effect],
                );
            }
        });

        return $this->afterWrite($user);
    }

    /**
     * Reload the override set after a write.
     *
     * STEP 3 memoises `permissionOverrides` on the model instance, so an instance
     * that resolved its permissions earlier in the same request would keep
     * serving the pre-write collection. Dropping the relation first guarantees
     * the current request sees what was just written. The memo lives on the
     * instance only — there is no global cache, and a later request loads the
     * rows from the database as usual.
     *
     * @return Collection<int, UserPermission>
     */
    private function afterWrite(User $user): Collection
    {
        $user->unsetRelation('permissionOverrides');

        return $this->overridesFor($user);
    }

    /**
     * Turn the submitted list into `permission_id => effect`.
     *
     * The Form Request already rejects repeats, but the guard is repeated here
     * so a direct service call can never silently let the last entry win.
     *
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    private function normalise(array $overrides): array
    {
        $desired = [];
        $duplicates = [];

        foreach ($overrides as $override) {
            $permissionId = (int) ($override['permission_id'] ?? 0);

            if (array_key_exists($permissionId, $desired)) {
                $duplicates[$permissionId] = $permissionId;

                continue;
            }

            $desired[$permissionId] = (string) ($override['effect'] ?? '');
        }

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'overrides' => [
                    'Each permission may appear only once. Duplicated: '
                        .implode(', ', array_keys($duplicates)).'.',
                ],
            ]);
        }

        return $desired;
    }

    /**
     * Reject permission ids that are not in the global catalogue.
     *
     * Permissions have no `business_id`, so existence is the only check needed —
     * there is no ownership to verify. Ids are resolved in one query and anything
     * unknown is reported as invalid input (422), matching the role endpoints.
     *
     * @param  array<int, int>  $ids
     *
     * @throws ValidationException
     */
    private function assertPermissionsExist(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $missing = array_values(array_diff($ids, $this->permissions->existingIds($ids)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permission_id' => [
                    'The following permission ids do not exist: '.implode(', ', $missing).'.',
                ],
            ]);
        }
    }
}
