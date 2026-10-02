<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 24B — permission catalogue API.
 *
 * The catalogue is global (no `business_id`), so the tests assert that two
 * different businesses read exactly the same list, and that the endpoints are
 * read-only.
 */
class PermissionApiTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->userWithPermissions([PermissionService::PERMISSIONS_VIEW]);
    }

    /** @test */
    public function the_permission_endpoint_requires_authentication(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/permissions')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated', 'errors' => []]);
    }

    /** @test */
    public function the_permission_endpoint_requires_the_view_permission(): void
    {
        $this->userWithoutPermissions($this->user->business_id);

        $this->getJson('/api/v1/permissions')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
    }

    /** @test */
    public function permissions_can_be_listed(): void
    {
        $this->permissionsBySlug(['roles.view', 'roles.update', 'sales.create']);

        $response = $this->getJson('/api/v1/permissions');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Permissions retrieved successfully'])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [['id', 'name', 'slug', 'description']],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        $this->assertContains('roles.view', array_column($response->json('data.items'), 'slug'));
    }

    /** @test */
    public function permissions_can_be_searched(): void
    {
        $this->permissionsBySlug(['roles.view', 'sales.create', 'purchases.create']);

        $response = $this->getJson('/api/v1/permissions?search=roles');

        $response->assertStatus(200)->assertJsonPath('data.pagination.total', 1);

        $this->assertSame('roles.view', $response->json('data.items.0.slug'));
    }

    /** @test */
    public function permissions_can_be_paginated(): void
    {
        $this->permissionsBySlug(['a.one', 'b.two', 'c.three']);

        $response = $this->getJson('/api/v1/permissions?per_page=2&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2);
    }

    /** @test */
    public function the_catalogue_is_global_and_identical_for_every_business(): void
    {
        $this->permissionsBySlug(['roles.view']);

        $businessA = Business::factory()->create();
        $userA = $this->userWithPermissions([PermissionService::PERMISSIONS_VIEW], $businessA->id);
        $slugsForA = $this->getJson('/api/v1/permissions')->json('data.pagination.total');

        $businessB = Business::factory()->create();
        $this->userWithPermissions([PermissionService::PERMISSIONS_VIEW], $businessB->id);
        $slugsForB = $this->getJson('/api/v1/permissions')->json('data.pagination.total');

        $this->assertNotNull($userA->business_id);
        $this->assertNotNull($businessB->id);
        $this->assertSame($slugsForA, $slugsForB);
    }

    /** @test */
    public function permissions_cannot_be_created_updated_or_deleted(): void
    {
        $permission = Permission::create(['name' => 'Roles View', 'slug' => 'roles.view']);

        // Every write verb is either 405 (method not allowed) or 403; none may
        // ever create, change or remove a global permission.
        $writes = [
            ['POST', '/api/v1/permissions', ['name' => 'X', 'slug' => 'x']],
            ['PUT', '/api/v1/permissions/'.$permission->id, ['name' => 'Y']],
            ['PATCH', '/api/v1/permissions/'.$permission->id, ['name' => 'Y']],
            ['DELETE', '/api/v1/permissions/'.$permission->id, []],
        ];

        foreach ($writes as [$method, $uri, $payload]) {
            $response = $this->json($method, $uri, $payload);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404, 405],
                "{$method} {$uri} must not mutate the catalogue.",
            );
        }

        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'slug' => 'roles.view']);
    }

    /** @test */
    public function assigning_an_unknown_permission_id_to_a_role_is_rejected(): void
    {
        $role = Role::create([
            'business_id' => $this->user->business_id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->userWithPermissions(
            [PermissionService::ROLES_CREATE, PermissionService::ROLES_UPDATE],
            $this->user->business_id
        );

        $this->postJson('/api/v1/roles', [
            'name' => 'Invalid',
            'permission_ids' => [999999],
        ])->assertStatus(422);

        // Nothing was written for the rejected request.
        $this->assertDatabaseMissing('roles', ['name' => 'Invalid']);
        $this->assertSame(0, DB::table('permission_role')->where('role_id', $role->id)->count());
    }
}
