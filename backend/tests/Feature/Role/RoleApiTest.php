<?php

declare(strict_types=1);

namespace Tests\Feature\Role;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Services\Role\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 24B — Role API contract, permission synchronisation and business
 * isolation.
 */
class RoleApiTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();

        // Every permission the role endpoints require, so each test can drop the
        // one it is actually asserting on.
        $this->user = $this->userWithPermissions(PermissionService::ALL_CAPABILITIES, $this->business->id);
    }

    /** @test */
    public function role_endpoints_require_authentication(): void
    {
        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->flushHeaders();

        $unauthenticated = [
            ['GET', '/api/v1/roles'],
            ['POST', '/api/v1/roles'],
            ['GET', '/api/v1/roles/'.$role->id],
            ['PUT', '/api/v1/roles/'.$role->id],
            ['PATCH', '/api/v1/roles/'.$role->id],
            ['DELETE', '/api/v1/roles/'.$role->id],
        ];

        foreach ($unauthenticated as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'Nope'])
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function listing_roles_requires_the_view_permission(): void
    {
        $this->userWithoutPermissions($this->business->id);

        $this->getJson('/api/v1/roles')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
    }

    /** @test */
    public function a_user_can_list_only_their_own_business_roles(): void
    {
        Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        Role::create(['business_id' => $this->business->id, 'name' => 'Cashier', 'slug' => 'cashier']);
        // Another tenant's role must never appear.
        Role::create(['business_id' => $this->otherBusiness->id, 'name' => 'Foreign', 'slug' => 'foreign']);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Roles retrieved successfully'])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [[
                        'id', 'name', 'slug', 'description', 'permissions', 'users_count',
                        'created_at', 'updated_at',
                    ]],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        // The acting user's own fixture role also belongs to this business, so
        // the assertion is "our roles are present and the foreign one is not"
        // rather than a global count.
        $names = array_column($response->json('data.items'), 'name');

        $this->assertContains('Manager', $names);
        $this->assertContains('Cashier', $names);
        $this->assertNotContains('Foreign', $names);

        foreach ($response->json('data.items') as $item) {
            $this->assertArrayNotHasKey('business_id', $item);
        }
    }

    /** @test */
    public function roles_can_be_paginated(): void
    {
        foreach (['A', 'B', 'C'] as $slug) {
            Role::create(['business_id' => $this->business->id, 'name' => $slug, 'slug' => strtolower($slug)]);
        }

        // Four roles exist in this business: the fixture role plus A, B and C.
        $response = $this->getJson('/api/v1/roles?page=2&per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->assertCount(2, $response->json('data.items'));
    }

    /** @test */
    public function roles_can_be_searched(): void
    {
        Role::create(['business_id' => $this->business->id, 'name' => 'Store Manager', 'slug' => 'store-manager']);
        Role::create(['business_id' => $this->business->id, 'name' => 'Cashier', 'slug' => 'cashier']);

        $response = $this->getJson('/api/v1/roles?search=manager');

        $response->assertStatus(200)->assertJsonPath('data.pagination.total', 1);

        $this->assertSame('Store Manager', $response->json('data.items.0.name'));
    }

    /** @test */
    public function a_role_can_be_created_with_permissions(): void
    {
        $permissions = $this->permissionsBySlug(['roles.view', 'roles.update']);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Manager',
            'description' => 'Store manager',
            'permission_ids' => $permissions->pluck('id')->all(),
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Role created successfully'])
            ->assertJsonPath('data.name', 'Manager')
            ->assertJsonPath('data.slug', 'manager')
            ->assertJsonPath('data.description', 'Store manager');

        // Ownership is always the caller's business, never a client value.
        $this->assertDatabaseHas('roles', [
            'name' => 'Manager',
            'slug' => 'manager',
            'business_id' => $this->business->id,
        ]);

        $this->assertCount(2, $response->json('data.permissions'));

        // Scoped to the new role: the fixture role also holds pivot rows.
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $response->json('data.id'),
            'permission_id' => $permissions[0]->id,
        ]);
    }

    /** @test */
    public function creating_a_role_ignores_a_client_supplied_business_id(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'Manager',
            'business_id' => $this->otherBusiness->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('roles', [
            'name' => 'Manager',
            'business_id' => $this->business->id,
        ]);

        $this->assertDatabaseMissing('roles', [
            'name' => 'Manager',
            'business_id' => $this->otherBusiness->id,
        ]);
    }

    /** @test */
    public function creating_a_role_validates_the_payload(): void
    {
        $this->postJson('/api/v1/roles', [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['errors' => ['name']]);

        // Over-long name (the column is 50 characters).
        $this->postJson('/api/v1/roles', ['name' => str_repeat('a', 51)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->postJson('/api/v1/roles', ['name' => 'Manager', 'permission_ids' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['permission_ids']]);

        $this->postJson('/api/v1/roles', ['name' => 'Manager', 'permission_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['permission_ids.0']]);
    }

    /** @test */
    public function a_duplicate_canonical_identifier_in_the_same_business_is_rejected(): void
    {
        Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->postJson('/api/v1/roles', ['name' => 'Manager'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame(1, Role::query()->forBusiness($this->business->id)->where('slug', 'manager')->count());
    }

    /** @test */
    public function the_same_role_name_may_exist_in_two_different_businesses(): void
    {
        Role::create(['business_id' => $this->otherBusiness->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->postJson('/api/v1/roles', ['name' => 'Manager'])->assertStatus(201);

        // The name now exists once in each business, not twice in one.
        $this->assertSame(1, Role::query()->forBusiness($this->business->id)->where('slug', 'manager')->count());
        $this->assertSame(1, Role::query()->forBusiness($this->otherBusiness->id)->where('slug', 'manager')->count());
    }

    /** @test */
    public function a_role_can_be_retrieved(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->getJson('/api/v1/roles/'.$role->id)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Role retrieved successfully',
                'data' => ['id' => $role->id, 'name' => 'Manager', 'slug' => 'manager'],
            ]);
    }

    /** @test */
    public function a_role_can_be_updated(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->putJson('/api/v1/roles/'.$role->id, [
            'name' => 'Store Manager',
            'description' => 'Updated manager role',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Store Manager')
            ->assertJsonPath('data.slug', 'store-manager')
            ->assertJsonPath('data.description', 'Updated manager role');
    }

    /** @test */
    public function updating_a_role_synchronises_its_permissions_without_duplicates(): void
    {
        $permissions = $this->permissionsBySlug(['roles.view', 'roles.update', 'roles.delete']);

        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $role->permissions()->sync([$permissions[0]->id, $permissions[1]->id]);

        $response = $this->patchJson('/api/v1/roles/'.$role->id, [
            'permission_ids' => [$permissions[1]->id, $permissions[2]->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'Manager');

        $granted = $response->json('data.permissions.*.slug');
        sort($granted);

        $this->assertSame(['roles.delete', 'roles.update'], $granted);

        // Exactly two pivot rows for this role — sync never duplicates.
        $this->assertSame(2, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    /** @test */
    public function updating_a_role_rolls_back_when_a_permission_id_is_invalid(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->putJson('/api/v1/roles/'.$role->id, [
            'name' => 'Renamed',
            'permission_ids' => [999999],
        ])->assertStatus(422);

        // Neither the rename nor any pivot row survived.
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Manager', 'slug' => 'manager']);
        $this->assertSame(0, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    /** @test */
    public function an_unused_role_can_be_deleted(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $role->permissions()->sync($this->permissionsBySlug(['roles.view'])->pluck('id')->all());

        $this->deleteJson('/api/v1/roles/'.$role->id)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Role deleted successfully'])
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        // The pivot rows cascade with the role.
        $this->assertSame(0, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    /** @test */
    public function deleting_an_assigned_role_returns_a_conflict_and_keeps_it(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $role->users()->attach($this->user);

        $response = $this->deleteJson('/api/v1/roles/'.$role->id);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => RoleService::IN_USE_DELETE_MESSAGE,
                'errors' => [],
            ]);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $this->user->id]);
    }

    /** @test */
    public function business_a_cannot_see_a_role_from_business_b(): void
    {
        $foreign = Role::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Manager',
            'slug' => 'foreign-manager',
        ]);

        $this->getJson('/api/v1/roles/'.$foreign->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);
    }

    /** @test */
    public function business_a_cannot_update_a_role_from_business_b(): void
    {
        $foreign = Role::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Manager',
            'slug' => 'foreign-manager',
        ]);

        $this->putJson('/api/v1/roles/'.$foreign->id, ['name' => 'Hijacked'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('roles', ['id' => $foreign->id, 'name' => 'Foreign Manager']);
    }

    /** @test */
    public function business_a_cannot_delete_a_role_from_business_b(): void
    {
        $foreign = Role::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Manager',
            'slug' => 'foreign-manager',
        ]);

        $this->deleteJson('/api/v1/roles/'.$foreign->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('roles', ['id' => $foreign->id]);
    }

    /** @test */
    public function role_responses_never_leak_internal_details(): void
    {
        $body = (string) $this->getJson('/api/v1/roles/999999')->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }
}
