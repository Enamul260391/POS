<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * STEP 3 — user ALLOW/DENY overrides in effective permission resolution.
 *
 * Covers the precedence `user DENY > user ALLOW > role permission > no access`
 * and the tenant boundary that stops a cross-business override from having any
 * effect. Override rows are written directly here; the management API is a
 * later step.
 */
class UserPermissionOverrideResolutionTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** @test */
    public function role_permission_still_works_when_no_override_exists(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        $this->assertTrue($user->hasPermission(PermissionService::SALES_VIEW));
        $this->assertFalse($user->hasPermission(PermissionService::SALES_CREATE));

        $this->authenticate($user);
        $this->getJson('/api/v1/sales')->assertStatus(200);
        $this->getJson('/api/v1/roles')->assertStatus(403);
    }

    /** @test */
    public function an_allow_override_grants_a_permission_the_role_lacks(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        $this->assertFalse($user->hasPermission(PermissionService::PRODUCTS_DELETE));

        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $this->authenticate($user);

        $this->assertTrue($user->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));

        // The gate really opens: an absent product yields 404, never 403.
        $this->deleteJson('/api/v1/products/999999')->assertStatus(404);
    }

    /** @test */
    public function a_deny_override_removes_a_role_grant(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_CREATE], $business->id);

        $this->assertTrue($user->hasPermission(PermissionService::SALES_CREATE));

        $this->overrideSlug($user, PermissionService::SALES_CREATE, UserPermission::EFFECT_DENY);
        $this->authenticate($user);

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::SALES_CREATE));

        // The real `can:sales.create` boundary refuses the request.
        $this->postJson('/api/v1/sales', [])->assertStatus(403);
    }

    /** @test */
    public function a_deny_override_beats_an_allow_override_for_the_same_capability(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        // `products.delete` resolves through exactly one canonical slug now that
        // the coarse `products.manage` alias is gone, and the unique index
        // forbids two override rows for one permission anyway. The DENY is
        // therefore written by flipping the single row, and it must win over
        // every role that would otherwise have granted the capability.
        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);

        $this->assertTrue($user->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));

        UserPermission::query()
            ->where('user_id', $user->getKey())
            ->update(['effect' => UserPermission::EFFECT_DENY]);

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
    }

    /** @test */
    public function a_deny_override_beats_every_role_that_grants_the_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $roleA = $this->roleFor($business->id, 'role-a', [PermissionService::SALES_CREATE]);
        $roleB = $this->roleFor($business->id, 'role-b', [PermissionService::SALES_CREATE]);
        $user->roles()->sync([$roleA->getKey(), $roleB->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertTrue($user->fresh()->hasPermission(PermissionService::SALES_CREATE));

        $this->overrideSlug($user, PermissionService::SALES_CREATE, UserPermission::EFFECT_DENY);

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::SALES_CREATE));
    }

    /** @test */
    public function an_allow_override_works_alongside_multiple_roles(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $roleA = $this->roleFor($business->id, 'role-a', [PermissionService::SALES_VIEW]);
        $roleB = $this->roleFor($business->id, 'role-b', [PermissionService::PRODUCTS_VIEW]);
        $user->roles()->sync([$roleA->getKey(), $roleB->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));

        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $fresh = $user->fresh();

        // The ALLOW adds the new capability without disturbing the role union.
        $this->assertTrue($fresh->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->assertTrue($fresh->hasPermission(PermissionService::SALES_VIEW));
        $this->assertTrue($fresh->hasPermission(PermissionService::PRODUCTS_VIEW));
    }

    /** @test */
    public function no_override_preserves_the_original_role_behaviour(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        // Granted stays granted, ungranted stays denied.
        $this->assertTrue($user->hasPermission(PermissionService::SALES_VIEW));
        $this->assertFalse($user->hasPermission(PermissionService::SALES_UPDATE));
        $this->assertSame([PermissionService::SALES_VIEW], $user->effectivePermissionSlugs());
    }

    /** @test */
    public function a_cross_business_override_cannot_change_authorization(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $businessA->id);

        // Forged rows: they reference this user but belong to business B. Written
        // with a raw insert because the future write path will not allow them.
        $this->rawOverride($businessB->id, (int) $user->getKey(), PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $this->rawOverride($businessB->id, (int) $user->getKey(), PermissionService::SALES_VIEW, UserPermission::EFFECT_DENY);

        $fresh = $user->fresh();

        // The mismatched ALLOW must not grant...
        $this->assertFalse($fresh->hasPermission(PermissionService::PRODUCTS_DELETE));

        // ...and the mismatched DENY must not revoke the role's own grant.
        $this->assertTrue($fresh->hasPermission(PermissionService::SALES_VIEW));
    }

    /** @test */
    public function another_users_override_cannot_affect_the_current_user(): void
    {
        $business = Business::factory()->create();

        $userA = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);
        $userB = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        $this->overrideSlug($userA, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $this->overrideSlug($userA, PermissionService::SALES_VIEW, UserPermission::EFFECT_DENY);

        $this->assertTrue($userA->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->assertFalse($userA->fresh()->hasPermission(PermissionService::SALES_VIEW));

        // B shares the business but holds none of A's overrides.
        $this->assertFalse($userB->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->assertTrue($userB->fresh()->hasPermission(PermissionService::SALES_VIEW));
    }

    /** @test */
    public function overrides_are_resolved_against_the_canonical_fine_grained_permissions(): void
    {
        $business = Business::factory()->create();

        // The full products domain, granted explicitly, plus an unrelated ALLOW.
        // The override feature resolves against the same canonical slugs the role
        // grants use, so nothing here depends on an alias.
        $user = $this->userWithPermissions([
            PermissionService::PRODUCTS_VIEW,
            PermissionService::PRODUCTS_DELETE,
        ], $business->id);

        $this->assertTrue($user->hasPermission(PermissionService::PRODUCTS_VIEW));
        $this->assertTrue($user->hasPermission(PermissionService::PRODUCTS_DELETE));

        // An ALLOW for `users.update` grants its own capability without widening
        // the products set, and the users domain says nothing about roles.
        $this->overrideSlug($user, PermissionService::USERS_UPDATE, UserPermission::EFFECT_ALLOW);
        $fresh = $user->fresh();

        $this->assertTrue($fresh->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->assertTrue($fresh->hasPermission(PermissionService::USERS_UPDATE));
        $this->assertFalse($fresh->hasPermission(PermissionService::ROLES_VIEW));
    }

    /** @test */
    public function an_override_on_a_retired_manage_slug_has_no_effect(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        // An ALLOW on a legacy `<resource>.manage` row authorises nothing, because
        // no capability resolves through that slug any more. The row may still
        // exist until the cleanup migration runs; until then it is inert.
        $this->rawOverride((int) $user->business_id, (int) $user->getKey(), 'products.manage', UserPermission::EFFECT_ALLOW);

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::PRODUCTS_VIEW));
        $this->assertFalse($user->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));

        // The same is true of a DENY: it cannot revoke anything either, because
        // the capability it names no longer resolves through that slug.
        $this->rawOverride((int) $user->business_id, (int) $user->getKey(), 'sales.manage', UserPermission::EFFECT_DENY);
        $this->assertTrue($user->fresh()->hasPermission(PermissionService::SALES_VIEW));
    }

    /** @test */
    public function resolved_capabilities_reflect_allow_and_deny_overrides(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::PRODUCTS_VIEW], $business->id);
        $service = app(PermissionService::class);

        $this->assertContains(PermissionService::PRODUCTS_VIEW, $service->resolvedCapabilities($user->fresh()));
        $this->assertNotContains(PermissionService::PRODUCTS_DELETE, $service->resolvedCapabilities($user->fresh()));

        // An ALLOW adds the capability to the published list.
        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $this->assertContains(PermissionService::PRODUCTS_DELETE, $service->resolvedCapabilities($user->fresh()));

        // Flipping the same permission to DENY removes it again.
        UserPermission::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->getKey())
            ->update(['effect' => UserPermission::EFFECT_DENY]);

        $this->assertNotContains(PermissionService::PRODUCTS_DELETE, $service->resolvedCapabilities($user->fresh()));
    }

    /** @test */
    public function login_and_me_capabilities_agree_with_the_overridden_authorization(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);
        $this->overrideSlug($user, PermissionService::SALES_VIEW, UserPermission::EFFECT_DENY);

        $token = $user->createToken('override-me')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $fromMe = $this->withHeaders($headers)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->json('data.capabilities');

        $this->assertContains(PermissionService::PRODUCTS_DELETE, $fromMe);
        $this->assertNotContains(PermissionService::SALES_VIEW, $fromMe);

        // The published list matches what the API enforces for the same token.
        $this->withHeaders($headers)->deleteJson('/api/v1/products/999999')->assertStatus(404);
        $this->withHeaders($headers)->getJson('/api/v1/sales')->assertStatus(403);

        // `login` publishes the identical list.
        $fromLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(200)->json('data.user.capabilities');

        $this->assertSame($fromMe, $fromLogin);
        $this->assertContains(PermissionService::PRODUCTS_DELETE, $fromLogin);
        $this->assertNotContains(PermissionService::SALES_VIEW, $fromLogin);
    }

    /** @test */
    public function resolving_capabilities_reads_overrides_at_most_once(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);
        $this->overrideSlug($user, PermissionService::PRODUCTS_DELETE, UserPermission::EFFECT_ALLOW);

        $fresh = $user->fresh();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // 50+ capability checks must not fan out into one query per check.
        app(PermissionService::class)->resolvedCapabilities($fresh);

        $overrideReads = count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'user_permissions'),
        ));

        $this->assertSame(1, $overrideReads, 'Overrides must be read once, not per capability: '.json_encode($queries));
    }

    /** Insert an override for the user inside the user's own business. */
    private function overrideSlug(User $user, string $slug, string $effect): void
    {
        $this->rawOverride((int) $user->business_id, (int) $user->getKey(), $slug, $effect);
    }

    /**
     * Insert an override with an explicit `business_id`, so a test can forge the
     * cross-business row the resolver has to ignore.
     */
    private function rawOverride(int $businessId, int $userId, string $slug, string $effect): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucwords(str_replace(['.', '_'], ' ', $slug))],
        );

        UserPermission::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'permission_id' => $permission->getKey(),
            'effect' => $effect,
        ]);
    }
}
