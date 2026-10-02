<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 2 — persistence infrastructure for per-user permission overrides.
 *
 * These tests cover the new `user_permissions` table and its model only. The
 * authorization resolver deliberately does not read overrides yet, so the final
 * test proves that authorization still behaves exactly as before while the
 * table is empty.
 */
class UserPermissionOverrideTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** @test */
    public function an_allow_override_can_be_created(): void
    {
        [$business, $user, $permission] = $this->fixtures();

        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        $this->assertSame(UserPermission::EFFECT_ALLOW, $override->fresh()->effect);
        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);
    }

    /** @test */
    public function a_deny_override_can_be_created(): void
    {
        [$business, $user, $permission] = $this->fixtures();

        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_DENY);

        $this->assertSame(UserPermission::EFFECT_DENY, $override->fresh()->effect);
        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_DENY,
        ]);
    }

    /** @test */
    public function a_duplicate_override_for_the_same_user_and_permission_is_rejected(): void
    {
        [$business, $user, $permission] = $this->fixtures();

        $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        // The `(business_id, user_id, permission_id)` unique index must refuse a
        // second row for the same user + permission, whatever the effect.
        $this->expectException(QueryException::class);

        $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_DENY);
    }

    /** @test */
    public function deleting_a_user_removes_its_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('user_permissions', ['id' => $override->id]);
    }

    /** @test */
    public function deleting_a_permission_removes_its_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        $permission->delete();

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
        $this->assertDatabaseMissing('user_permissions', ['id' => $override->id]);
    }

    /** @test */
    public function soft_deleting_a_business_keeps_its_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        // Businesses are soft-deleted, so a normal `delete()` never reaches the
        // foreign key and the tenant's data is retained for a possible restore.
        $business->delete();

        $this->assertSoftDeleted('businesses', ['id' => $business->id]);
        $this->assertDatabaseHas('user_permissions', ['id' => $override->id]);
    }

    /** @test */
    public function force_deleting_a_business_removes_its_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        // A hard delete reaches the `business_id` foreign key, which cascades —
        // an override cannot survive independently of the tenant that owns it.
        $business->forceDelete();

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
        $this->assertDatabaseMissing('user_permissions', ['id' => $override->id]);
    }

    /** @test */
    public function the_user_relationship_lists_the_users_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        $this->assertCount(1, $user->fresh()->permissionOverrides);
        $this->assertTrue($user->fresh()->permissionOverrides->first()->is($override));
    }

    /** @test */
    public function the_permission_relationship_lists_the_permissions_overrides(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_ALLOW);

        $this->assertCount(1, $permission->fresh()->userOverrides);
        $this->assertTrue($permission->fresh()->userOverrides->first()->is($override));
    }

    /** @test */
    public function an_override_belongs_to_the_expected_business_user_and_permission(): void
    {
        [$business, $user, $permission] = $this->fixtures();
        $override = $this->makeOverride($business, $user, $permission, UserPermission::EFFECT_DENY);

        $this->assertSame($business->id, $override->business_id);
        $this->assertTrue($override->business->is($business));
        $this->assertTrue($override->user->is($user));
        $this->assertTrue($override->permission->is($permission));
        $this->assertTrue($business->fresh()->userPermissionOverrides->contains($override));
    }

    /** @test */
    public function existing_authorization_is_unchanged_while_no_overrides_exist(): void
    {
        $business = Business::factory()->create();

        // A role in the user's own business grants exactly `sales.view`.
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        // The new table exists but holds nothing for this user...
        $this->assertSame(0, DB::table('user_permissions')->count());
        $this->assertCount(0, $user->fresh()->permissionOverrides);

        // ...so the role grant alone still decides access, exactly as before:
        // the granted capability passes, an ungranted one is refused.
        $this->assertTrue($user->hasPermission(PermissionService::SALES_VIEW));
        $this->assertFalse($user->hasPermission(PermissionService::SALES_CREATE));
        $this->assertTrue($user->can(PermissionService::SALES_VIEW));
        $this->assertFalse($user->can(PermissionService::SALES_CREATE));

        // The real HTTP boundary is unchanged too: `can:sales.view` still lets
        // this user through and still stops them at an ungranted endpoint.
        $this->getJson('/api/v1/sales')->assertStatus(200);
        $this->getJson('/api/v1/roles')->assertStatus(403);
    }

    /**
     * A tenant, a user inside it and a permission to override.
     *
     * @return array{0: Business, 1: User, 2: Permission}
     */
    private function fixtures(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $permission = Permission::create([
            'name' => 'Sales View',
            'slug' => 'sales.view',
        ]);

        return [$business, $user, $permission];
    }

    private function makeOverride(
        Business $business,
        User $user,
        Permission $permission,
        string $effect,
    ): UserPermission {
        return UserPermission::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => $effect,
        ]);
    }
}
