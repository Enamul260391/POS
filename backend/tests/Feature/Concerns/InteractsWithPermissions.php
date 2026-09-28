<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Helpers for building the role/permission fixtures the Phase 24B tests need.
 *
 * `userWithPermissions()` is the important one: it creates a role owned by the
 * user's own business, attaches only the requested permissions, and returns the
 * user. Tests therefore grant exactly the ability under test instead of blanket
 * admin access, which is what makes the 403 cases meaningful.
 */
trait InteractsWithPermissions
{
    /**
     * Create a user in the given business holding exactly the given permissions.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    protected function userWithPermissions(array $permissionSlugs, ?int $businessId = null): User
    {
        // Deliberately *not* `actingAsBusinessUser()`: that helper grants every
        // capability, which would make these "exactly one permission" fixtures
        // indistinguishable from a fully privileged user and silently defeat the
        // 403 assertions this trait exists to support.
        $user = $businessId === null
            ? $this->actingAsUnprivilegedUser()
            : User::factory()->create(['business_id' => $businessId]);

        $role = Role::create([
            'business_id' => $user->business_id,
            'name' => 'Test Role',
            'slug' => 'test-role-'.$user->getKey(),
            'description' => 'Role used by the authorization tests.',
        ]);

        $role->permissions()->sync(
            $this->permissionsBySlug($permissionSlugs)->pluck('id')->all()
        );

        $user->roles()->sync([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->authenticate($user);

        return $user;
    }

    /**
     * Create a user in the given business with a working token, but no roles.
     *
     * Used for the "authenticated but not permitted" cases.
     */
    protected function userWithoutPermissions(?int $businessId = null): User
    {
        $user = $businessId === null
            ? $this->actingAsUnprivilegedUser()
            : User::factory()->create(['business_id' => $businessId]);

        $this->authenticate($user);

        return $user;
    }

    /**
     * Make the given user the identity of every subsequent request.
     *
     * `authenticate()` itself lives in `InteractsWithPosApi`; this trait relies
     * on it, so any class using `InteractsWithPermissions` must also use
     * `InteractsWithPosApi` (all of them do).
     */

    /**
     * Create (or reuse) permissions by slug.
     *
     * @param  array<int, string>  $slugs
     * @return Collection<int, Permission>
     */
    protected function permissionsBySlug(array $slugs)
    {
        $permissions = collect();

        foreach ($slugs as $slug) {
            $permissions->push(Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace(['.', '_'], ' ', $slug))],
            ));
        }

        return $permissions;
    }

    /**
     * Create a role in the given business with the given permission slugs.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    protected function roleFor(int $businessId, string $slug, array $permissionSlugs = []): Role
    {
        $role = Role::create([
            'business_id' => $businessId,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Fixture role.',
        ]);

        $role->permissions()->sync(
            $this->permissionsBySlug($permissionSlugs)->pluck('id')->all()
        );

        return $role;
    }
}
