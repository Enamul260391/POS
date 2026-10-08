<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 4 — Platform business-user creation.
 */
class PlatformBusinessUserCreateTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function base(Business $business): string
    {
        return '/api/v1/platform/businesses/'.$business->getKey().'/users';
    }

    private function roleFor(Business $business, string $slug = 'cashier'): Role
    {
        return Role::create([
            'business_id' => $business->getKey(),
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function asOwner(): User
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Role $role, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Platform Created',
            'email' => 'platform-created-'.uniqid().'@example.com',
            'phone' => '01700000001',
            'branch' => 'Main',
            'notes' => 'Created by platform owner.',
            'status' => User::STATUS_ACTIVE,
            'role_id' => $role->getKey(),
            'password' => self::PASSWORD,
        ], $overrides);
    }

    /** @test */
    public function platform_owner_can_create_a_user_for_a_business(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        $response = $this->postJson($this->base($business), $this->payload($role))
            ->assertCreated()
            ->assertJson(['success' => true, 'message' => 'User created successfully']);

        $userId = $response->json('data.id');
        $this->assertNotNull($userId);
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'business_id' => $business->getKey(),
            'platform_role' => null,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('role_user', ['user_id' => $userId, 'role_id' => $role->getKey()]);
    }

    /** @test */
    public function created_user_is_not_platform_owner(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        $userId = $this->postJson($this->base($business), $this->payload($role))
            ->assertCreated()->json('data.id');

        $created = User::findOrFail($userId);
        $this->assertFalse($created->isPlatformOwner());
        $this->assertNull($created->platform_role);
        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function request_cannot_override_business_id(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $role = $this->roleFor($businessA);

        $this->postJson($this->base($businessA), $this->payload($role, [
            'business_id' => $businessB->getKey(),
        ]))->assertStatus(422);

        $this->assertDatabaseCount('users', 1);
    }

    /** @test */
    public function platform_identity_fields_cannot_be_set_on_create(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        foreach ([['platform_role' => 'owner'], ['is_platform_owner' => true]] as $extra) {
            $this->postJson($this->base($business), $this->payload($role, $extra))
                ->assertStatus(422);
        }

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function created_password_is_securely_hashed(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        $userId = $this->postJson($this->base($business), $this->payload($role))
            ->assertCreated()->json('data.id');

        $stored = User::findOrFail($userId);
        $this->assertNotSame(self::PASSWORD, $stored->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored->password));
    }

    /** @test */
    public function duplicate_email_is_rejected(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);
        $existing = User::factory()->create(['business_id' => $business->getKey()]);
        $existing->roles()->sync([$role->getKey()]);

        $this->postJson($this->base($business), $this->payload($role, ['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function invalid_role_is_rejected(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $roleA = $this->roleFor($businessA);
        $foreignRole = $this->roleFor($businessB, 'foreign');

        $this->postJson($this->base($businessA), $this->payload($roleA, [
            'role_id' => $foreignRole->getKey(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['role_id']);

        $this->postJson($this->base($businessA), $this->payload($roleA, ['role_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['role_id']);

        $this->assertSame(1, User::count());
    }

    /** @test */
    public function user_creation_is_transaction_safe_when_role_is_invalid(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        $this->postJson($this->base($business), $this->payload($role, ['role_id' => 999999]))
            ->assertStatus(422);

        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('role_user', 0);
    }
}
