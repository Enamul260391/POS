<?php

declare(strict_types=1);

namespace Tests\Feature\UserRole;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 24B — user ↔ role assignment and its business-isolation guarantees.
 */
class UserRoleApiTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $admin;

    private User $member;

    private Business $otherBusiness;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();

        $this->member = User::factory()->create(['business_id' => $this->business->id]);
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);

        $this->admin = $this->userWithPermissions(
            [PermissionService::USERS_ROLES_UPDATE],
            $this->business->id,
        );
    }

    /** @test */
    public function the_endpoint_requires_authentication(): void
    {
        $this->flushHeaders();

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => []])
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated', 'errors' => []]);
    }

    /** @test */
    public function the_endpoint_requires_the_users_roles_update_permission(): void
    {
        $this->userWithoutPermissions($this->business->id);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => []])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
    }

    /** @test */
    public function business_a_can_assign_its_own_roles_to_its_own_user(): void
    {
        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $response = $this->putJson('/api/v1/users/'.$this->member->id.'/roles', [
            'role_ids' => [$role->id],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User roles updated successfully'])
            ->assertJsonPath('data.user.id', $this->member->id)
            ->assertJsonPath('data.roles.0.slug', 'manager');

        $this->assertDatabaseHas('role_user', [
            'user_id' => $this->member->id,
            'role_id' => $role->id,
        ]);
    }

    /** @test */
    public function roles_are_replaced_not_appended(): void
    {
        $manager = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $cashier = Role::create(['business_id' => $this->business->id, 'name' => 'Cashier', 'slug' => 'cashier']);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => [$manager->id]]);
        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => [$cashier->id]]);

        $this->assertDatabaseHas('role_user', ['user_id' => $this->member->id, 'role_id' => $cashier->id]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $this->member->id, 'role_id' => $manager->id]);
    }

    /** @test */
    public function an_empty_role_list_detaches_every_role(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $this->member->roles()->attach($role);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => []])
            ->assertStatus(200)
            ->assertJsonPath('data.roles', []);

        $this->assertDatabaseMissing('role_user', ['user_id' => $this->member->id, 'role_id' => $role->id]);
    }

    /** @test */
    public function an_invalid_role_id_is_rejected(): void
    {
        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role_ids.0']]);

        $this->assertSame(0, DB::table('role_user')->where('user_id', $this->member->id)->count());
    }

    /** @test */
    public function the_role_ids_field_must_be_present_and_an_array(): void
    {
        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role_ids']]);
    }

    /** @test */
    public function business_a_cannot_assign_a_role_from_business_b(): void
    {
        $foreignRole = Role::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Manager',
            'slug' => 'foreign-manager',
        ]);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', ['role_ids' => [$foreignRole->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role_ids.0']]);

        // No cross-tenant pivot row was created.
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->member->id,
            'role_id' => $foreignRole->id,
        ]);
    }

    /** @test */
    public function a_mixed_batch_of_own_and_foreign_roles_is_rejected_entirely(): void
    {
        $own = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);
        $foreign = Role::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign',
            'slug' => 'foreign',
        ]);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', [
            'role_ids' => [$own->id, $foreign->id],
        ])->assertStatus(422);

        // Transactional behaviour: the valid half must not be applied either.
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->member->id,
            'role_id' => $own->id,
        ]);
    }

    /** @test */
    public function business_a_cannot_modify_the_roles_of_a_business_b_user(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->putJson('/api/v1/users/'.$this->otherUser->id.'/roles', ['role_ids' => [$role->id]])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->otherUser->id,
            'role_id' => $role->id,
        ]);
    }

    /** @test */
    public function business_b_cannot_receive_a_role_from_business_a(): void
    {
        $ownRole = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        // Act as an administrator of business B.
        $this->userWithPermissions(
            [PermissionService::USERS_ROLES_UPDATE],
            $this->otherBusiness->id
        );

        $this->putJson('/api/v1/users/'.$this->otherUser->id.'/roles', ['role_ids' => [$ownRole->id]])
            ->assertStatus(422);

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->otherUser->id,
            'role_id' => $ownRole->id,
        ]);
    }

    /** @test */
    public function the_endpoint_ignores_a_client_supplied_business_id(): void
    {
        $role = Role::create(['business_id' => $this->business->id, 'name' => 'Manager', 'slug' => 'manager']);

        $this->putJson('/api/v1/users/'.$this->member->id.'/roles', [
            'role_ids' => [$role->id],
            'business_id' => $this->otherBusiness->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('role_user', ['user_id' => $this->member->id, 'role_id' => $role->id]);
    }

    /** @test */
    public function an_administrator_cannot_grant_themselves_extra_permissions_via_the_role_endpoint(): void
    {
        // The admin holds only `users.roles.update`, so the roles endpoints are
        // closed to them — role escalation is not reachable through this API.
        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->postJson('/api/v1/roles', ['name' => 'Sneaky'])->assertStatus(403);
    }
}
