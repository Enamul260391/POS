<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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
        $user = $businessId === null
            ? $this->actingAsBusinessUser()
            : User::factory()->create(['business_id' => $businessId]);

        $role = Role::create([
            'business_id' => $user->business_id,
            'name' => 'Test Role',
            'slug' => 'test-role-'.$user->getKey(),
            'description' => 'Role used by the Phase 24B tests.',
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
            ? $this->actingAsBusinessUser()
            : User::factory()->create(['business_id' => $businessId]);

        $this->authenticate($user);

        return $user;
    }

    /**
     * Make the given user the identity of every subsequent request.
     *
     * `Auth::forgetGuards()` is required, not optional: Laravel resolves and
     * caches the authenticated user for the lifetime of the test, so simply
     * issuing a new token would keep the *previous* identity active and make
     * per-permission assertions silently test the wrong user.
     */
    protected function authenticate(User $user): void
    {
        Auth::forgetGuards();

        $this->withToken($user->createToken('feature-test')->plainTextToken);
    }

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
