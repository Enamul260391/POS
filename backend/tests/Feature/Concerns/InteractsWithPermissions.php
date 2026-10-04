<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
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
     * Accepts capability names as well as raw slugs, because a test almost always
     * thinks in capabilities (`INVENTORY_MANAGE`) while the row it needs is the
     * slug behind it (`inventory.adjust`). Resolution goes through
     * {@see PermissionService::slugFor()} so the fixture always materialises the
     * permission the *current* authorization model actually checks — a test can
     * never accidentally create a row that authorises nothing.
     *
     * A name that is not a known capability is used verbatim, which is what lets
     * the negative regression tests plant a retired `<resource>.manage` row and
     * prove it no longer grants anything.
     *
     * @param  array<int, string>  $slugs
     * @return Collection<int, Permission>
     */
    protected function permissionsBySlug(array $slugs)
    {
        $permissions = collect();

        foreach ($slugs as $slug) {
            $permissions->push(Permission::firstOrCreate(
                ['slug' => $this->permissionSlug($slug)],
                ['name' => ucfirst(str_replace(['.', '_'], ' ', $slug))],
            ));
        }

        return $permissions;
    }

    /**
     * The catalogue slug a requested capability or slug resolves to.
     *
     * One authoritative mapping, so no fixture has to restate which slugs differ
     * from their capability name.
     */
    protected function permissionSlug(string $capabilityOrSlug): string
    {
        return PermissionService::isKnown($capabilityOrSlug)
            ? PermissionService::slugFor($capabilityOrSlug)
            : $capabilityOrSlug;
    }

    /**
     * Plant a permission row under its exact slug, bypassing resolution.
     *
     * Needed for the negative regression tests. Two slugs are simultaneously a
     * retired coarse permission *and* a live capability name —
     * `inventory.manage` and `cash_registers.manage` — so a fixture that simply
     * "grants this slug" would silently resolve them to `inventory.adjust` /
     * `cash_registers.transactions.create` and prove nothing. This says
     * "materialise this literal row" and nothing more.
     */
    protected function literalPermission(string $slug): Permission
    {
        return Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst(str_replace(['.', '_'], ' ', $slug))],
        );
    }

    /**
     * A user whose role holds exactly the given literal permission rows.
     *
     * The negative-test counterpart to {@see userWithPermissions()}: it grants
     * the exact slugs given, so a test can prove a retired `<resource>.manage`
     * row authorises nothing.
     *
     * @param  array<int, string>  $slugs
     */
    protected function userWithLiteralPermissions(array $slugs, ?int $businessId = null): User
    {
        $user = $businessId === null
            ? $this->actingAsUnprivilegedUser()
            : User::factory()->create(['business_id' => $businessId]);

        $role = Role::create([
            'business_id' => $user->business_id,
            'name' => 'Test Role',
            'slug' => 'test-literal-role-'.$user->getKey(),
            'description' => 'Role holding literal, unresolved permission slugs.',
        ]);

        $role->permissions()->sync(
            collect($slugs)->map(fn (string $slug): int => $this->literalPermission($slug)->getKey())->all()
        );

        $user->roles()->sync([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->authenticate($user);

        return $user;
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
