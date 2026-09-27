<?php

declare(strict_types=1);

namespace Tests\Feature\Role;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::factory()->create();
        $this->businessB = Business::factory()->create();
    }

    /** @test */
    public function role_belongs_to_business(): void
    {
        $role = Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->assertTrue($role->business->is($this->businessA));
    }

    /** @test */
    public function business_has_many_roles(): void
    {
        $role = Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->assertTrue($this->businessA->roles->contains($role));
        $this->assertFalse($this->businessB->roles->contains($role));
    }

    /** @test */
    public function role_requires_a_business_id(): void
    {
        $this->expectException(QueryException::class);

        Role::create([
            'name' => 'Manager',
            'slug' => 'manager',
        ]);
    }

    /** @test */
    public function same_role_slug_can_exist_in_different_businesses(): void
    {
        Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        Role::create([
            'business_id' => $this->businessB->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->assertDatabaseHas('roles', [
            'business_id' => $this->businessA->id,
            'slug' => 'manager',
        ]);
        $this->assertDatabaseHas('roles', [
            'business_id' => $this->businessB->id,
            'slug' => 'manager',
        ]);
    }

    /** @test */
    public function duplicate_canonical_role_slug_in_same_business_fails(): void
    {
        Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $this->expectException(QueryException::class);

        Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);
    }

    /** @test */
    public function role_rejects_an_invalid_business_id(): void
    {
        $this->expectException(QueryException::class);

        Role::create([
            'business_id' => 999999,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);
    }

    /** @test */
    public function existing_role_user_and_permission_role_relationships_remain_intact(): void
    {
        $role = Role::create([
            'business_id' => $this->businessA->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);
        $user = User::factory()->create(['business_id' => $this->businessA->id]);
        $permission = Permission::create([
            'name' => 'View Reports',
            'slug' => 'reports.view',
        ]);

        $role->users()->attach($user);
        $role->permissions()->attach($permission);

        $this->assertDatabaseHas('role_user', [
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertTrue($role->users->contains($user));
        $this->assertTrue($role->permissions->contains($permission));
    }
}
