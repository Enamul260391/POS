<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 8 — Platform Business First Admin Provisioning.
 *
 * Tests the `POST /api/v1/platform/businesses/{business}/admin` endpoint
 * which provisions the first Business Admin for a newly created business.
 */
class PlatformBusinessAdminProvisioningTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function base(Business $business): string
    {
        return '/api/v1/platform/businesses/'.$business->getKey().'/admin';
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
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'First Admin',
            'email' => 'first-admin-'.uniqid().'@example.com',
            'phone' => '01700000001',
            'branch' => 'Main',
            'notes' => 'Provisioned by platform owner.',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    /**
     * Ensure the admin role exists for the given business.
     */
    private function ensureAdminRole(Business $business): Role
    {
        return Role::query()
            ->forBusiness($business->getKey())
            ->where('slug', 'admin')
            ->firstOrFail();
    }

    /**
     * Create a business via the platform API (which creates default roles).
     */
    private function createBusinessWithRoles(): Business
    {
        $response = $this->postJson('/api/v1/platform/businesses', [
            'name' => 'Test Business '.uniqid(),
            'currency' => 'USD',
            'timezone' => 'UTC',
        ])->assertCreated();

        return Business::findOrFail($response->json('data.id'));
    }

    /**
     * The middleware stack a live route runs, exactly as Laravel resolves it.
     *
     * @return array<int, string>
     */
    private function middlewareFor(string $method, string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }

        $this->fail("No route registered for {$method} {$uri}.");
    }

    /** @test */
    public function unauthenticated_user_cannot_provision_admin(): void
    {
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload())
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->assertDatabaseCount('users', 0);
    }

    /** @test */
    public function normal_business_user_cannot_provision_admin(): void
    {
        $business = Business::factory()->create();
        $this->actingAsBusinessUser();

        $this->postJson($this->base($business), $this->payload())
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertDatabaseCount('users', 1); // only the acting user
    }

    /** @test */
    public function platform_owner_can_provision_first_admin(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->assertDatabaseCount('users', 1); // only platform owner

        $response = $this->postJson($this->base($business), $this->payload())
            ->assertCreated()
            ->assertJson(['success' => true, 'message' => 'First Business Admin created successfully']);

        $userId = $response->json('data.id');
        $this->assertNotNull($userId);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'business_id' => $business->getKey(),
            'platform_role' => null,
            'status' => User::STATUS_ACTIVE,
        ]);

        $created = User::findOrFail($userId);
        $this->assertTrue($created->hasRole('admin'));
        $this->assertSame($business->getKey(), (int) $created->business_id);
    }

    /** @test */
    public function created_admin_is_not_platform_owner(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $userId = $this->postJson($this->base($business), $this->payload())
            ->assertCreated()->json('data.id');

        $created = User::findOrFail($userId);
        $this->assertFalse($created->isPlatformOwner());
        $this->assertNull($created->platform_role);
        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function created_admin_has_full_admin_permissions(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $userId = $this->postJson($this->base($business), $this->payload())
            ->assertCreated()->json('data.id');

        $created = User::findOrFail($userId);
        $this->assertTrue($created->hasRole('admin'));

        // Admin role should have all permissions
        $adminRole = Role::query()
            ->where('business_id', $business->getKey())
            ->where('slug', 'admin')
            ->firstOrFail();

        $this->assertTrue($created->roles->contains($adminRole));
        $this->assertGreaterThan(0, $adminRole->permissions()->count());
    }

    /** @test */
    public function created_admin_password_is_securely_hashed(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $userId = $this->postJson($this->base($business), $this->payload())
            ->assertCreated()->json('data.id');

        $stored = User::findOrFail($userId);
        $this->assertNotSame(self::PASSWORD, $stored->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored->password));
    }

    /** @test */
    public function duplicate_email_is_rejected(): void
    {
        $this->asOwner();
        $business = $this->createBusinessWithRoles();
        $existing = User::factory()->create(['business_id' => $business->getKey()]);
        $adminRole = $this->ensureAdminRole($business);
        $existing->roles()->sync([$adminRole->getKey()]);

        $this->postJson($this->base($business), $this->payload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function duplicate_first_admin_is_rejected(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        // First provisioning succeeds
        $this->postJson($this->base($business), $this->payload())
            ->assertCreated();

        // Second provisioning fails with 409
        $this->postJson($this->base($business), $this->payload(['email' => 'another-admin@example.com']))
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        // Only one admin exists
        $this->assertSame(2, User::count()); // platform owner + first admin
    }

    /** @test */
    public function validation_requires_name(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function validation_requires_email(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function validation_rejects_invalid_email(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function validation_requires_password(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['password' => '', 'password_confirmation' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function validation_requires_password_confirmation_matching(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['password' => 'password123', 'password_confirmation' => 'different']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password_confirmation']);
    }

    /** @test */
    public function validation_enforces_minimum_password_length(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->postJson($this->base($business), $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function platform_identity_fields_cannot_be_set(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        foreach ([
            ['business_id' => 999],
            ['platform_role' => 'owner'],
            ['is_platform_owner' => true],
            ['role_id' => 999],
            ['status' => 'inactive'],
        ] as $extra) {
            $this->postJson($this->base($business), $this->payload($extra))
                ->assertStatus(422);
        }

        // Only the platform owner should exist
        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function provisioning_is_transactional_on_failure(): void
    {
        $this->asOwner();
        $business = $this->createBusinessWithRoles();

        // Trigger a failure by providing duplicate email with existing user
        $existing = User::factory()->create(['business_id' => $business->getKey()]);
        $adminRole = $this->ensureAdminRole($business);
        $existing->roles()->sync([$adminRole->getKey()]);

        $this->postJson($this->base($business), $this->payload(['email' => $existing->email]))
            ->assertStatus(422);

        // No partial admin created
        $this->assertSame(2, User::count()); // platform owner + existing user
        $this->assertDatabaseCount('role_user', 1); // only existing user's role
    }

    /** @test */
    public function provisioning_for_missing_business_returns_404(): void
    {
        $this->asOwner();

        $this->postJson('/api/v1/platform/businesses/999999/admin', $this->payload())
            ->assertNotFound();
    }

    /** @test */
    public function provisioning_for_soft_deleted_business_returns_404(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $business->delete();

        $this->postJson($this->base($business), $this->payload())
            ->assertNotFound();
    }

    /** @test */
    public function route_carries_correct_middleware_stack(): void
    {
        $this->assertContains('auth:sanctum', $this->middlewareFor('POST', 'api/v1/platform/businesses/{business}/admin'));
        $this->assertContains('active', $this->middlewareFor('POST', 'api/v1/platform/businesses/{business}/admin'));
        $this->assertContains('platform', $this->middlewareFor('POST', 'api/v1/platform/businesses/{business}/admin'));
        $this->assertNotContains('business', $this->middlewareFor('POST', 'api/v1/platform/businesses/{business}/admin'));
    }

    /** @test */
    public function tenant_isolation_business_a_admin_cannot_access_business_b(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        // Provision admin for Business A
        $userId = $this->postJson($this->base($businessA), $this->payload())
            ->assertCreated()->json('data.id');

        $adminA = User::findOrFail($userId);

        // Admin A belongs to Business A
        $this->assertSame($businessA->getKey(), (int) $adminA->business_id);

        // Provision admin for Business B
        $userIdB = $this->postJson($this->base($businessB), $this->payload(['email' => 'admin-b@example.com']))
            ->assertCreated()->json('data.id');

        $adminB = User::findOrFail($userIdB);

        // Admin B belongs to Business B
        $this->assertSame($businessB->getKey(), (int) $adminB->business_id);

        // They are distinct users
        $this->assertNotSame($adminA->getKey(), $adminB->getKey());
    }

    /** @test */
    public function business_user_cannot_access_platform_admin_provisioning_endpoint(): void
    {
        $this->asOwner();
        $business = $this->createBusinessWithRoles();
        $user = User::factory()->create(['business_id' => $business->getKey()]);
        $adminRole = $this->ensureAdminRole($business);
        $user->roles()->sync([$adminRole->getKey()]);

        $this->authenticate($user);

        $this->postJson($this->base($business), $this->payload())
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function platform_owner_can_provision_admin_for_multiple_businesses(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create(['name' => 'Tenant A']);
        $businessB = Business::factory()->create(['name' => 'Tenant B']);

        $adminAId = $this->postJson($this->base($businessA), $this->payload())
            ->assertCreated()->json('data.id');
        $adminBId = $this->postJson($this->base($businessB), $this->payload(['email' => 'admin-b@example.com']))
            ->assertCreated()->json('data.id');

        $this->assertNotSame($adminAId, $adminBId);
        $this->assertSame(3, User::count()); // platform owner + 2 admins
    }

    /** @test */
    public function provisioned_admin_can_login_and_access_tenant_resources(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $email = 'login-test-'.uniqid().'@example.com';
        $userId = $this->postJson($this->base($business), $this->payload(['email' => $email]))
            ->assertCreated()->json('data.id');

        // Login as the provisioned admin
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertArrayHasKey('token', $loginResponse->json('data'));
        $this->assertSame($business->getKey(), $loginResponse->json('data.user.business_id'));
        $this->assertTrue($loginResponse->json('data.user.is_platform_owner') === false);
    }

    /** @test */
    public function response_does_not_contain_password_or_hash(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $response = $this->postJson($this->base($business), $this->payload())
            ->assertCreated();

        $json = $response->json('data');
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('password_hash', $json);
        $this->assertArrayNotHasKey('remember_token', $json);
    }
}