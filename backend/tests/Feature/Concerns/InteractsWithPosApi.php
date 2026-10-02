<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication helpers for Phase 20 feature tests.
 *
 * `actingAsBusinessUser()` creates a user inside the given business (a fresh
 * business when none is supplied), grants it every capability the API defines,
 * and installs a real Sanctum bearer token as the default header, so every
 * subsequent request in the test authenticates exactly like production traffic.
 *
 * The full grant matters now that the operational endpoints are capability-gated
 * (see `routes/api.php`): these suites test domain behaviour — validation,
 * tenancy, stock, money maths — not authorization, so their user has to be
 * allowed through the gate. Authorization itself is covered by
 * `Tests\Feature\Authorization\OperationalPermissionTest`, which grants exactly
 * one capability at a time.
 *
 * `headersFor()` produces per-request headers for a second identity without
 * replacing the default one — per-call headers win over the defaults in
 * Laravel's test request pipeline, which lets a single test act as two
 * tenants (e.g. to prove cross-business access returns 404).
 */
trait InteractsWithPosApi
{
    /**
     * Create a user in the given (or a new) business and authenticate as them
     * for the rest of the test, granting every capability the API defines.
     *
     * The user is granted the complete capability set, so a test that is not
     * about authorization is never blocked by a 403 it did not arrange.
     */
    protected function actingAsBusinessUser(?Business $business = null): User
    {
        $user = $this->createBusinessUser($business);

        $this->grantAllCapabilities($user);

        return $user;
    }

    /**
     * Create and authenticate a user who holds **no** permission at all.
     *
     * This is the "authenticated but not authorized" identity: the 403 path.
     * It is deliberately separate from `actingAsBusinessUser()` so a test can
     * prove a capability is actually required.
     */
    protected function actingAsUnprivilegedUser(?Business $business = null): User
    {
        $user = $this->createBusinessUser($business);

        $this->authenticate($user);

        return $user;
    }

    /**
     * Create a business user and install their Sanctum bearer token as the
     * default header for every subsequent request.
     */
    protected function createBusinessUser(?Business $business = null): User
    {
        $business ??= Business::factory()->create();

        $user = User::factory()->create(['business_id' => $business->id]);

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
     * Give a user a role holding every capability the API authorises against.
     *
     * Permissions are global, so they are created once and reused across every
     * test in the suite via `firstOrCreate`. The role is owned by the user's own
     * business, because `User::permissions()` only counts roles whose
     * `business_id` matches the user's — a role from another tenant would grant
     * nothing at all.
     *
     * The rows are created from {@see PermissionService::catalogueSlugs()} — the
     * canonical slug of every capability — rather than from the capability names
     * themselves. The two are almost always the same string, but not always: the
     * stock-adjustment and cash-movement capabilities are named `inventory.manage`
     * and `cash_registers.manage` while their permissions are `inventory.adjust`
     * and `cash_registers.transactions.create`. Granting the capability name
     * verbatim would plant a row the resolver does not read, and every operational
     * endpoint in the suite would 403 for a user who was supposed to have full
     * access.
     */
    protected function grantAllCapabilities(User $user): Role
    {
        $permissionIds = [];

        foreach (PermissionService::catalogueSlugs() as $slug) {
            $permissionIds[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace(['.', '_'], ' ', $slug))],
            )->id;
        }

        $role = Role::firstOrCreate(
            ['business_id' => $user->business_id, 'slug' => 'test-full-access'],
            ['name' => 'Test Full Access', 'description' => 'Fixture role holding every capability.'],
        );

        $role->permissions()->sync($permissionIds);

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $role;
    }

    /**
     * Authorization headers authenticating as the given user, applied to a
     * single request only.
     *
     * @return array<string, string>
     */
    protected function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('feature-test')->plainTextToken,
        ];
    }
}
