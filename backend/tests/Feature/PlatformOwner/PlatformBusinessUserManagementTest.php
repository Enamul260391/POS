<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 4 — the Platform Owner's Business User Management API (part 1: auth).
 *
 * Endpoints under `/api/v1/platform/businesses/{business}/users`, each
 * behind `auth:sanctum` + `active` + `platform` and its own
 * `platform.users.*` Gate. Boundary failures land at the
 * middleware/Gate layer — never inside the controller.
 */
class PlatformBusinessUserManagementTest extends TestCase
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

    private function memberFor(Business $business, ?Role $role = null): User
    {
        $role ??= $this->roleFor($business);

        $user = User::factory()->create([
            'business_id' => $business->getKey(),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->roles()->sync([$role->getKey()]);

        return $user;
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
            'role_id' => $role->getKey(),
            'password' => self::PASSWORD,
        ], $overrides);
    }

    private function asOwner(): User
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        return $owner;
    }

    /** @test */
    public function unauthenticated_user_cannot_list_users(): void
    {
        $business = Business::factory()->create();

        $this->getJson($this->base($business))
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_view_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->getJson($this->base($business).'/'.$member->getKey())
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_user(): void
    {
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        $this->postJson($this->base($business), $this->payload($role))
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->assertDatabaseCount('users', 0);
    }

    /** @test */
    public function unauthenticated_user_cannot_update_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->putJson($this->base($business).'/'.$member->getKey(), ['name' => 'Renamed'])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_change_status(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_INACTIVE]
        )->assertUnauthorized()->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_sync_roles(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->putJson(
            $this->base($business).'/'.$member->getKey().'/roles',
            ['role_ids' => []]
        )->assertUnauthorized()->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->deleteJson($this->base($business).'/'.$member->getKey())
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }
}
