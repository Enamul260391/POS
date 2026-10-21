<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Providers\AppServiceProvider;
use App\Services\Permission\PermissionService;
use App\Services\UserPermission\UserPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * STEP 10 — the final authorization audit.
 *
 * Everything asserted here was chosen because the *runtime* system was the only
 * place the answer could be trusted: the capability table is read back out of
 * `PermissionService`, the route contract is scraped out of Laravel's own route
 * table rather than from a hand-written fixture, and precedence is exercised
 * through the real `PermissionService` / Gate / service objects instead of a
 * re-implementation of the algorithm. A test that restates the rules proves
 * nothing; these read the rules back.
 *
 * Three areas are covered that no earlier suite asserts directly:
 *
 *  1. **Route-level capability coverage.** Everything before this checked
 *     capability -> slug. Nothing checked the opposite direction — that the
 *     `can:` middleware actually attached to live routes names capabilities that
 *     exist, and names no retired `<resource>.manage` slug. A typo there fails
 *     *closed* (the endpoint becomes permanently unreachable), and a retired slug
 *     fails the other way: `slugsFor()` maps an unknown name to itself and no
 *     capability resolves through it, so the endpoint would be guarded by a
 *     permission no role can ever hold.
 *
 *  2. **Memoisation invalidation on the same object.** STEP 3 memoises
 *     `permissionOverrides` on the model instance, so the interesting question
 *     is not "does a fresh instance see the write" (trivially yes) but "does the
 *     *same* instance that already resolved its permissions see it". That is
 *     exactly what `UserPermissionService::afterWrite()` exists to guarantee.
 *
 *  3. **Cross-tenant ALLOW against an in-tenant DENY.** The precedence tests
 *     prove DENY beats ALLOW *within* a tenant and that a foreign row is
 *     ignored *entirely*. The combination — a real DENY in the user's own
 *     business plus a forged ALLOW for the same permission in another business —
 *     is what an attacker would actually attempt, and it is not the same test as
 *     either half.
 */
class AuthorizationSecurityAuditTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /**
     * The retired coarse permission slugs.
     *
     * Listed once so the route audit and the capability audit cannot drift apart
     * in what they consider "retired".
     *
     * @var array<int, string>
     */
    private const RETIRED_SLUGS = [
        'products.manage',
        'sales.manage',
        'purchases.manage',
        'customers.manage',
        'suppliers.manage',
        'expenses.manage',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'inventory.manage',
        'cash_registers.manage',
        'categories.manage',
        'sale_returns.manage',
        'expense_categories.manage',
        'reports.manage',
    ];

    // ------------------------------------------------------------------
    // 1. Route-level capability coverage
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * Every `can:` ability on a live *tenant* route must be a declared tenant
     * capability.
     *
     * Platform routes (`api/v1/platform/*`) are excluded by design: the Step 2
     * boundary keeps platform authorisation (`platform.businesses.*` Gates in
     * `AppServiceProvider::PLATFORM_ABILITIES`) structurally separate from the
     * tenant permission model, so `PermissionService::isKnown()` is *false*
     * for them on purpose (see
     * `PlatformOwnerAuthorizationBoundaryTest::platform_abilities_do_not_appear_in_the_tenant_permission_catalogue`).
     * Including them here would demand the very merge Step 2 forbids. They
     * are audited in the other direction below instead.
     *
     * Read from `Router::getRoutes()` rather than from a fixture list, so a route
     * added after this test was written is included and a route moved off a valid
     * capability is caught.
     */
    public function no_live_route_guards_itself_with_an_unknown_capability(): void
    {
        $unknown = [];

        foreach ($this->liveTenantRouteCapabilities() as $capability => $routes) {
            if (! PermissionService::isKnown($capability)) {
                $unknown[$capability] = $routes;
            }
        }

        $this->assertSame([], $unknown, 'Routes guard themselves with unknown capabilities.');
    }

    /**
     * @test
     *
     * No *tenant* route may be guarded by a retired `<resource>.manage` slug.
     *
     * Platform routes are out of scope here for the same reason as above:
     * each `platform.businesses.*` ability resolves through the platform
     * identity, never through `PermissionService::slugsFor()`, so no retired
     * slug can ever authorise them.
     *
     * `inventory.manage` and `cash_registers.manage` are *live capability names*
     * and legitimately appear in the route table, so the assertion is on the
     * slug each name resolves through — `inventory.adjust` and
     * `cash_registers.transactions.create` — never on the name itself.
     */
    public function no_live_route_resolves_through_a_retired_permission_slug(): void
    {
        $offenders = [];

        foreach ($this->liveTenantRouteCapabilities() as $capability => $routes) {
            foreach (PermissionService::slugsFor($capability) as $slug) {
                if (in_array($slug, self::RETIRED_SLUGS, true)) {
                    $offenders[$capability] = ['slug' => $slug, 'routes' => $routes];
                }
            }
        }

        $this->assertSame([], $offenders, 'A route authorises through a retired coarse slug.');
    }

    /**
     * @test
     *
     * The tenant route surface and the tenant capability table agree exactly —
     * 56 each, no capability that nothing routes, no route capability nothing
     * declares.
     *
     * Platform routes are excluded for the same reason as above: the five
     * `platform.businesses.*` abilities are not tenant capabilities and must
     * never be. They are pinned to their own declared set in
     * `platform_routes_use_only_declared_platform_abilities` below.
     *
     * Asserted as an equality of two sets rather than as two counts, so a
     * capability added to `PermissionService` without a route (and therefore
     * without a gate check anywhere) fails here.
     *
     * The count itself is the second line of defence: the set equality above
     * already proves the two sides agree, so the number only pins *how many*
     * capabilities the tenant surface has, and it has to be moved whenever a
     * capability is legitimately added. STEP 4 added
     * `inventory.batches.view` (the least-privilege read the POS price
     * selector needs), which took the contract from 55 to 56.
     */
    public function every_capability_is_routed_and_every_route_capability_is_declared(): void
    {
        $routed = array_keys($this->liveTenantRouteCapabilities());

        sort($routed);
        $expected = PermissionService::ALL_CAPABILITIES;
        sort($expected);

        $this->assertSame($expected, $routed);
        $this->assertCount(56, $routed, 'The 56-capability route contract must remain intact.');
    }

    /**
     * @test
     *
     * The platform route surface agrees exactly with the declared platform
     * abilities: every `can:` on an `api/v1/platform/*` route names a member
     * of `AppServiceProvider::PLATFORM_ABILITIES`, and every declared ability
     * guards at least one platform route — so neither a typo'd ability nor an
     * unrouted one can hide.
     */
    public function platform_routes_use_only_declared_platform_abilities(): void
    {
        $routed = $this->livePlatformRouteCapabilities();
        $declared = AppServiceProvider::PLATFORM_ABILITIES;

        foreach (array_keys($routed) as $capability) {
            $this->assertContains(
                $capability,
                $declared,
                "Platform route ability {$capability} must be a declared platform ability.",
            );
        }

        foreach ($declared as $ability) {
            $this->assertArrayHasKey(
                $ability,
                $routed,
                "Declared platform ability {$ability} must guard at least one platform route.",
            );
        }

        $this->assertSame([], array_intersect(
            array_keys($routed),
            PermissionService::ALL_CAPABILITIES,
        ), 'Platform and tenant capabilities must never overlap.');
    }

    /**
     * @test
     *
     * The five capabilities whose names are easiest to break — the two whose
     * name differs from its slug, and the three anchoring the staff/role
     * administration surface — are routed under their published names.
     *
     * They are API surface: the client compares against exactly these strings,
     * so they must not be "corrected" to match their slugs.
     */
    public function the_capabilities_whose_names_differ_from_their_slugs_keep_their_names(): void
    {
        $routed = $this->liveTenantRouteCapabilities();

        foreach ([
            PermissionService::INVENTORY_MANAGE,
            PermissionService::CASH_REGISTERS_MANAGE,
            PermissionService::SALES_PAYMENTS_CREATE,
            PermissionService::USERS_ROLES_UPDATE,
            PermissionService::PERMISSIONS_VIEW,
        ] as $capability) {
            $this->assertArrayHasKey(
                $capability,
                $routed,
                "{$capability} must remain a live routed capability name.",
            );
        }

        // `users.roles.update` is the single capability guarding the whole
        // "who may this account do" surface: role assignment *and* the override
        // endpoints. Asserted in both directions — the sibling role-assignment
        // route is included deliberately, and no route guarded by this
        // capability reaches outside the user-administration group.
        $guarded = $routed[PermissionService::USERS_ROLES_UPDATE];

        $this->assertCount(5, $guarded, 'Only role assignment and the five override routes are guarded.');

        foreach ($guarded as $route) {
            $this->assertMatchesRegularExpression(
                '#^api/v1/users/\{user\}/(roles|permission-overrides)#',
                $route,
                'users.roles.update must guard nothing outside user authorisation.',
            );
        }
    }

    // ------------------------------------------------------------------
    // 2. Memoisation invalidation on the same object
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * A user instance that already resolved its permissions sees a later
     * override write — without being re-fetched.
     *
     * The realistic path is a request that authorises the target user and then
     * writes an override for them on the same instance: the middleware Gate
     * check primes the memo, the write happens after it. If `afterWrite()`'s
     * `unsetRelation('permissionOverrides')` were removed, the response would be
     * built from the pre-write collection and the API would report an override
     * state that was never committed.
     */
    public function an_override_write_invalidates_the_memo_on_the_same_user_instance(): void
    {
        $business = Business::factory()->create();

        $actor = $this->userWithPermissions(
            [PermissionService::USERS_ROLES_UPDATE],
            $business->id,
        );

        $target = User::factory()->create(['business_id' => $business->id]);
        $role = $this->roleFor($business->id, 'seller', [PermissionService::SALES_CREATE]);
        $target->roles()->sync([$role->getKey()]);
        $target->unsetRelation('roles')->unsetRelation('permissions');

        // Prime every memo this instance will ever use.
        $this->assertTrue($target->hasPermission(PermissionService::SALES_CREATE));
        $this->assertSame([PermissionService::SALES_CREATE], $target->effectivePermissionSlugs());

        $service = app(UserPermissionService::class);

        // Same instance throughout — no `fresh()`, no second query object.
        $service->upsert(
            (int) $business->id,
            $actor,
            $target,
            $this->permissionIdFor(PermissionService::SALES_CREATE),
            UserPermission::EFFECT_DENY,
        );

        $this->assertFalse(
            $target->hasPermission(PermissionService::SALES_CREATE),
            'The same instance must not keep serving the pre-write permission memo.',
        );
        $this->assertSame([], $target->effectivePermissionSlugs());

        // ...and the inverse, so the invalidation is not one-directional.
        $service->delete(
            (int) $business->id,
            $actor,
            $target,
            $this->permissionIdFor(PermissionService::SALES_CREATE),
        );

        $this->assertTrue(
            $target->hasPermission(PermissionService::SALES_CREATE),
            'Removing an override must restore the role grant on the same instance.',
        );
    }

    /**
     * @test
     *
     * The same invalidation holds for a full replace, and it holds for the
     * published capability list too — that is the list the client renders from,
     * so a stale memo there shows the user controls the server will reject.
     */
    public function resolved_capabilities_on_the_same_instance_reflect_a_replacement_write(): void
    {
        $business = Business::factory()->create();

        $actor = $this->userWithPermissions(
            [PermissionService::USERS_ROLES_UPDATE],
            $business->id,
        );

        $target = User::factory()->create(['business_id' => $business->id]);
        $role = $this->roleFor(
            $business->id,
            'clerk',
            [PermissionService::SALES_VIEW, PermissionService::SALES_CREATE],
        );
        $target->roles()->sync([$role->getKey()]);
        $target->unsetRelation('roles')->unsetRelation('permissions');

        $permissions = app(PermissionService::class);

        $before = $permissions->resolvedCapabilities($target);
        sort($before);

        $granted = [PermissionService::SALES_VIEW, PermissionService::SALES_CREATE];
        sort($granted);

        $this->assertSame(
            $granted,
            $before,
            'With no overrides, a role grants exactly its own capabilities.',
        );

        // Replace the whole set: one revoked, one granted that no role provides.
        app(UserPermissionService::class)->sync(
            (int) $business->id,
            $actor,
            $target,
            [
                ['permission_id' => $this->permissionIdFor(PermissionService::SALES_VIEW), 'effect' => 'deny'],
                ['permission_id' => $this->permissionIdFor(PermissionService::ROLES_UPDATE), 'effect' => 'allow'],
            ],
        );

        // Membership, not order: `resolvedCapabilities()` walks
        // `ALL_CAPABILITIES`, so the returned order is the capability table's
        // declaration order. Only the set is contractual.
        $after = $permissions->resolvedCapabilities($target);
        sort($after);

        $expected = [PermissionService::SALES_CREATE, PermissionService::ROLES_UPDATE];
        sort($expected);

        $this->assertSame($expected, $after);
    }

    // ------------------------------------------------------------------
    // 3. Cross-tenant ALLOW against an in-tenant DENY
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * A forged ALLOW in another business cannot lift a real DENY in this one.
     *
     * The two halves are already proven separately — DENY outranks ALLOW inside
     * a tenant, and a foreign row is ignored entirely. What an attacker actually
     * needs is both at once: leave the victim's DENY row where the resolver
     * honours it and add an ALLOW for the same permission under a business id
     * the resolver does not. Because the unique key includes `business_id`, those
     * two rows genuinely coexist, so this is a state the schema permits and the
     * resolver has to survive.
     */
    public function a_foreign_allow_cannot_lift_an_in_tenant_deny(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->userWithPermissions(
            [PermissionService::SALES_CREATE, PermissionService::ROLES_UPDATE],
            $businessA->id,
        );

        $rolesUpdate = $this->permissionIdFor(PermissionService::ROLES_UPDATE);

        // The genuine, in-tenant DENY.
        UserPermission::create([
            'business_id' => $businessA->id,
            'user_id' => $user->getKey(),
            'permission_id' => $rolesUpdate,
            'effect' => UserPermission::EFFECT_DENY,
        ]);

        // The forged, foreign ALLOW for the very same permission.
        UserPermission::create([
            'business_id' => $businessB->id,
            'user_id' => $user->getKey(),
            'permission_id' => $rolesUpdate,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);

        $fresh = $user->fresh();

        $this->assertFalse($fresh->hasPermission(PermissionService::ROLES_UPDATE));
        $this->assertNotContains(
            PermissionService::ROLES_UPDATE,
            app(PermissionService::class)->resolvedCapabilities($fresh),
        );

        // The unrelated role grant in the same tenant is untouched.
        $this->assertTrue($fresh->hasPermission(PermissionService::SALES_CREATE));
    }

    /**
     * @test
     *
     * The mirror image: a foreign DENY must not revoke a genuine in-tenant
     * ALLOW either.
     *
     * Without this, "foreign rows are ignored" would only be half-proved — an
     * implementation that treated a foreign row as authoritative for
     * *revocation* would pass the previous test while still letting one business
     * lock a user out of another business's installation.
     */
    public function a_foreign_deny_cannot_revoke_an_in_tenant_allow(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->userWithPermissions([], $businessA->id);

        $productsDelete = $this->permissionIdFor(PermissionService::PRODUCTS_DELETE);

        UserPermission::create([
            'business_id' => $businessA->id,
            'user_id' => $user->getKey(),
            'permission_id' => $productsDelete,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);

        UserPermission::create([
            'business_id' => $businessB->id,
            'user_id' => $user->getKey(),
            'permission_id' => $productsDelete,
            'effect' => UserPermission::EFFECT_DENY,
        ]);

        $fresh = $user->fresh();

        $this->assertTrue($fresh->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->assertContains(
            PermissionService::PRODUCTS_DELETE,
            app(PermissionService::class)->resolvedCapabilities($fresh),
        );
    }

    /**
     * @test
     *
     * The tenant filter holds when the overrides arrive as a *pre-loaded*
     * relation rather than through the tenant-scoped query.
     *
     * `User::tenantPermissionOverrides()` has two inputs: it runs a
     * `business_id`-constrained query when the relation is not loaded, and it
     * falls back to whatever is already on the instance when it is. The second
     * branch is the one worth proving, because a caller that eager-loads
     * `permissionOverrides` without the constraint hands the resolver a
     * collection that already contains foreign rows — and from that point the
     * in-memory filter is the *only* thing standing between a forged row and a
     * granted capability.
     *
     * Written as a mutation-sensitive test: removing the filter closure from
     * {@see User::tenantPermissionOverrides()} makes this fail, while the
     * query-scoped suites above keep passing.
     */
    public function a_pre_loaded_override_relation_is_still_filtered_to_the_users_own_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $businessA->id);

        $rolesUpdate = $this->permissionIdFor(PermissionService::ROLES_UPDATE);

        UserPermission::create([
            'business_id' => $businessA->id,
            'user_id' => $user->getKey(),
            'permission_id' => $rolesUpdate,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);

        // A foreign DENY for the same permission, in another business.
        UserPermission::create([
            'business_id' => $businessB->id,
            'user_id' => $user->getKey(),
            'permission_id' => $rolesUpdate,
            'effect' => UserPermission::EFFECT_DENY,
        ]);

        // Eager-load the relation *unconstrained*, exactly as an over-eager
        // caller would. Both rows are now on the instance.
        $preloaded = User::query()
            ->with('permissionOverrides')
            ->findOrFail($user->getKey());

        $this->assertCount(
            2,
            $preloaded->getRelation('permissionOverrides'),
            'The eager-loaded relation really does contain both rows.',
        );

        // The resolver must still refuse to honour the foreign DENY...
        $this->assertTrue(
            $preloaded->hasPermission(PermissionService::ROLES_UPDATE),
            'A foreign DENY on a pre-loaded relation must not revoke access.',
        );

        // ...and the effective set contains the in-tenant ALLOW exactly once,
        // with the foreign DENY having contributed nothing to it. (Both rows name
        // the same permission, so a leaked foreign DENY would have subtracted
        // `roles.update` back out — this assertion is what proves it did not.)
        $effective = $preloaded->effectivePermissionSlugs();
        sort($effective);

        $expected = [PermissionService::SALES_VIEW, PermissionService::ROLES_UPDATE];
        sort($expected);

        $this->assertSame($expected, $effective);
        $this->assertSame(
            [],
            $preloaded->deniedPermissionSlugs(),
            'The foreign DENY must not appear as a denial of anything.',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Every capability referenced by a `can:` middleware on a live route.
     *
     * `gatherMiddleware()` resolves group middleware, so the aliases applied by
     * the `api` / `auth:sanctum` groups are seen exactly as the routes run them.
     * The value is the list of routes using the capability, so a failure message
     * names the offending endpoint.
     *
     * @return array<string, array<int, string>>
     */
    private function liveRouteCapabilities(): array
    {
        return $this->collectRouteCapabilities(null);
    }

    /**
     * Capabilities on live routes outside `api/v1/platform/*`.
     *
     * The tenant-authorization audit surface: platform abilities are excluded
     * (see `no_live_route_guards_itself_with_an_unknown_capability`).
     *
     * @return array<string, array<int, string>>
     */
    private function liveTenantRouteCapabilities(): array
    {
        return $this->collectRouteCapabilities(false);
    }

    /**
     * Capabilities on live routes inside `api/v1/platform/*`.
     *
     * The platform-authorization audit surface, pinned to
     * `AppServiceProvider::PLATFORM_ABILITIES` by
     * `platform_routes_use_only_declared_platform_abilities`.
     *
     * @return array<string, array<int, string>>
     */
    private function livePlatformRouteCapabilities(): array
    {
        return $this->collectRouteCapabilities(true);
    }

    /**
     * Collect `can:` capabilities, optionally restricted to platform routes.
     *
     * @return array<string, array<int, string>>
     */
    private function collectRouteCapabilities(?bool $platformOnly): array
    {
        $capabilities = [];

        foreach (app(Router::class)->getRoutes() as $route) {
            $isPlatform = str_starts_with($route->uri(), 'api/v1/platform');

            if ($platformOnly === true && ! $isPlatform) {
                continue;
            }

            if ($platformOnly === false && $isPlatform) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                foreach (explode(',', substr($middleware, 4)) as $capability) {
                    $capability = trim($capability);

                    $capabilities[$capability][] = $route->uri().' ['.implode('|', $route->methods()).']';
                }
            }
        }

        return $capabilities;
    }

    /**
     * The catalogue id backing a capability.
     *
     * Resolved through {@see PermissionService::slugFor()} so the fixture
     * materialises the row the resolver actually reads — the two capabilities
     * whose name differs from their slug would otherwise plant a row nothing
     * authorises against, and every assertion here would pass vacuously.
     */
    private function permissionIdFor(string $capability): int
    {
        $slug = PermissionService::slugFor($capability);

        return Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucwords(str_replace(['.', '_'], ' ', $slug))],
        )->getKey();
    }
}
