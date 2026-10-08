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
 * Step 4 — Platform business-user auth: business users are refused.
 *
 * A normal business user (even one holding every tenant capability) must
 * receive no access to any platform user endpoint. Refusals land at the
 * `platform` middleware — before any `can:` Gate or controller logic.
 */
class PlatformBusinessUserAuthorizationTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

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

    /** @test */
    public function normal_business_user_cannot_list_platform_users(): void
    {
        $business = Business::factory()->create();
        $this->actingAsBusinessUser($business);

        $this->getJson($this->base($business))
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_view_platform_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $this->actingAsBusinessUser($business);

        $this->getJson($this->base($business).'/'.$member->getKey())
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_create_platform_user(): void
    {
        $business = Business::factory()->create();
        $role = $this->roleFor($business);
        $this->actingAsBusinessUser($business);

        $this->postJson($this->base($business), [
            'name' => 'Nope',
            'email' => 'nope-'.uniqid().'@example.com',
            'role_id' => $role->getKey(),
            'password' => 'correct-horse-battery-staple',
        ])->assertForbidden()->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_update_platform_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $this->actingAsBusinessUser($business);

        $this->putJson($this->base($business).'/'.$member->getKey(), ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_change_platform_user_status(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $this->actingAsBusinessUser($business);

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_INACTIVE]
        )->assertForbidden()->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_sync_platform_user_roles(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $this->actingAsBusinessUser($business);

        $this->putJson(
            $this->base($business).'/'.$member->getKey().'/roles',
            ['role_ids' => []]
        )->assertForbidden()->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_delete_platform_user(): void
    {
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $this->actingAsBusinessUser($business);

        $this->deleteJson($this->base($business).'/'.$member->getKey())
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }
}
