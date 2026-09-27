<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 24B — the 401 / 403 / 404 contract of the new endpoints, and the
 * permission-resolution rules behind it.
 *
 * The rule under test for every category: no token -> 401, a valid token
 * without the permission -> 403, a valid token with it -> success.
 */
class RolePermissionAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** Endpoint category => [method, uri, payload]. */
    private const CATEGORIES = [
        'roles.view' => ['GET', '/api/v1/roles', ['name' => 'Nope']],
        'roles.create' => ['POST', '/api/v1/roles', ['name' => 'Nope']],
        'roles.delete' => ['DELETE', '/api/v1/roles/1', []],
        'permissions.view' => ['GET', '/api/v1/permissions', []],
        'users.roles.update' => ['PUT', '/api/v1/users/1/roles', ['role_ids' => []]],
        'settings.view' => ['GET', '/api/v1/settings', []],
        'settings.update' => ['PUT', '/api/v1/settings', ['receipt_footer' => 'x']],
    ];

    /** @test */
    public function every_protected_category_requires_authentication(): void
    {
        $this->flushHeaders();

        foreach (self::CATEGORIES as $permission => [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function an_authenticated_user_without_the_permission_receives_403(): void
    {
        $business = Business::factory()->create();

        foreach (self::CATEGORIES as $permission => [$method, $uri, $payload]) {
            $this->userWithoutPermissions($business->id);

            $this->json($method, $uri, $payload)
                ->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Forbidden',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function an_authenticated_user_with_the_permission_is_not_blocked_by_authorization(): void
    {
        $business = Business::factory()->create();

        // `GET /roles/{id}` and `PUT /roles/{id}` are covered separately below
        // because they need a real, in-tenant role to address.
        foreach (self::CATEGORIES as $permission => [$method, $uri, $payload]) {
            // `userWithPermissions()` re-points the token at this identity.
            $this->userWithPermissions([$permission], $business->id);

            $response = $this->json($method, $uri, $payload);

            $this->assertNotContains(
                $response->getStatusCode(),
                [401, 403],
                "{$method} {$uri} must not reject a user holding {$permission}.",
            );
        }
    }

    /** @test */
    public function show_update_and_delete_require_their_own_permissions(): void
    {
        $business = Business::factory()->create();
        $role = Role::create([
            'business_id' => $business->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        // Denied without the permission...
        $this->userWithoutPermissions($business->id);
        $this->getJson('/api/v1/roles/'.$role->id)->assertStatus(403);
        $this->putJson('/api/v1/roles/'.$role->id, ['name' => 'X'])->assertStatus(403);
        $this->deleteJson('/api/v1/roles/'.$role->id)->assertStatus(403);

        // ...and allowed with it.
        $this->userWithPermissions([PermissionService::ROLES_VIEW], $business->id);
        $this->getJson('/api/v1/roles/'.$role->id)->assertStatus(200);

        $this->userWithPermissions([PermissionService::ROLES_UPDATE], $business->id);
        $this->putJson('/api/v1/roles/'.$role->id, ['name' => 'Renamed'])->assertStatus(200);

        $this->userWithPermissions([PermissionService::ROLES_DELETE], $business->id);
        $this->deleteJson('/api/v1/roles/'.$role->id)->assertStatus(200);
    }

    /** @test */
    public function a_403_never_leaks_internal_details(): void
    {
        $this->userWithoutPermissions(Business::factory()->create()->id);

        $body = (string) $this->getJson('/api/v1/roles')->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    /** @test */
    public function a_user_without_any_role_holds_no_permission(): void
    {
        $user = $this->userWithoutPermissions(Business::factory()->create()->id);

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $this->assertFalse($user->hasPermission($capability), "{$capability} must not be granted.");
            $this->assertFalse(Gate::forUser($user)->allows($capability));
        }
    }

    /** @test */
    public function the_fine_grained_permission_grants_exactly_its_own_capability(): void
    {
        $user = $this->userWithPermissions(
            [PermissionService::ROLES_VIEW],
            Business::factory()->create()->id,
        );

        $this->assertTrue($user->hasPermission(PermissionService::ROLES_VIEW));
        $this->assertFalse($user->hasPermission(PermissionService::ROLES_CREATE));
        $this->assertFalse($user->hasPermission(PermissionService::ROLES_UPDATE));
        $this->assertFalse($user->hasPermission(PermissionService::ROLES_DELETE));
        $this->assertFalse($user->hasPermission(PermissionService::SETTINGS_VIEW));
    }

    /** @test */
    public function the_legacy_manage_permission_still_satisfies_the_matching_capability(): void
    {
        // The seeded catalogue predates the fine-grained names, so `roles.manage`
        // must keep authorising role management rather than locking everyone out.
        $user = $this->userWithPermissions(
            ['roles.manage', 'settings.manage', 'users.manage'],
            Business::factory()->create()->id,
        );

        $this->assertTrue($user->hasPermission(PermissionService::ROLES_VIEW));
        $this->assertTrue($user->hasPermission(PermissionService::ROLES_CREATE));
        $this->assertTrue($user->hasPermission(PermissionService::ROLES_UPDATE));
        $this->assertTrue($user->hasPermission(PermissionService::ROLES_DELETE));
        $this->assertTrue($user->hasPermission(PermissionService::PERMISSIONS_VIEW));
        $this->assertTrue($user->hasPermission(PermissionService::SETTINGS_VIEW));
        $this->assertTrue($user->hasPermission(PermissionService::SETTINGS_UPDATE));
        $this->assertTrue($user->hasPermission(PermissionService::USERS_ROLES_UPDATE));
    }

    /** @test */
    public function permissions_held_through_any_of_a_users_roles_are_considered(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $viewer = Role::create(['business_id' => $business->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $editor = Role::create(['business_id' => $business->id, 'name' => 'Editor', 'slug' => 'editor']);

        $permission = Permission::create(['name' => 'Roles Update', 'slug' => 'roles.update']);
        $editor->permissions()->attach($permission);

        $user->roles()->attach([$viewer->id, $editor->id]);

        $this->assertTrue($user->fresh()->hasPermission(PermissionService::ROLES_UPDATE));
    }

    /** @test */
    public function a_role_from_another_business_never_grants_a_permission(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();

        $user = User::factory()->create(['business_id' => $business->id]);

        // The pivot row exists, but the role is owned by another tenant, so its
        // permissions must not count towards this user's capabilities.
        $foreignRole = Role::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Foreign Admin',
            'slug' => 'foreign-admin',
        ]);
        $foreignRole->permissions()->attach(
            Permission::create(['name' => 'Roles Update', 'slug' => 'roles.update'])->id
        );

        $user->roles()->attach($foreignRole);

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::ROLES_UPDATE));
    }
}
