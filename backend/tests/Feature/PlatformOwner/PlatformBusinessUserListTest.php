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
 * Step 4 — Platform business-user list / view (scoped, paginated, safe).
 */
class PlatformBusinessUserListTest extends TestCase
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

    private function memberFor(Business $business, ?Role $role = null, array $attrs = []): User
    {
        $role ??= $this->roleFor($business);

        $user = User::factory()->create(array_merge([
            'business_id' => $business->getKey(),
            'status' => User::STATUS_ACTIVE,
        ], $attrs));
        $user->roles()->sync([$role->getKey()]);

        return $user;
    }

    private function asOwner(): User
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        return $owner;
    }

    /** @test */
    public function platform_owner_can_list_users_of_a_business(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);
        $member = $this->memberFor($business, $role);

        $response = $this->getJson($this->base($business))->assertOk()
            ->assertJson(['success' => true, 'message' => 'Users retrieved successfully']);

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($member->getKey(), $ids);
    }

    /** @test */
    public function business_a_list_never_contains_business_b_users(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);
        $memberB = $this->memberFor($businessB);

        $ids = collect($this->getJson($this->base($businessA))->assertOk()->json('data.items'))
            ->pluck('id')->all();

        $this->assertContains($memberA->getKey(), $ids);
        $this->assertNotContains($memberB->getKey(), $ids);
    }

    /** @test */
    public function listing_supports_pagination(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $role = $this->roleFor($business);

        for ($i = 0; $i < 3; $i++) {
            $this->memberFor($business, $role, ['email' => 'page-'.$i.'-'.uniqid().'@example.com']);
        }

        $response = $this->getJson($this->base($business).'?per_page=2&page=1')->assertOk();

        $this->assertSame(2, count($response->json('data.items')));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));
    }

    /** @test */
    public function platform_owner_does_not_appear_as_a_tenant_user(): void
    {
        $owner = $this->asOwner();
        $business = Business::factory()->create();
        $this->memberFor($business);

        $ids = collect($this->getJson($this->base($business))->assertOk()->json('data.items'))
            ->pluck('id')->all();

        $this->assertNotContains($owner->getKey(), $ids);
    }

    /** @test */
    public function listing_never_exposes_password_or_tokens(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $this->memberFor($business);

        $item = $this->getJson($this->base($business))->assertOk()->json('data.items.0');

        foreach (['password', 'password_confirmation', 'remember_token', 'token', 'tokens'] as $secret) {
            $this->assertArrayNotHasKey($secret, $item);
        }
    }

    /** @test */
    public function platform_owner_can_view_a_user_of_the_selected_business(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->getJson($this->base($business).'/'.$member->getKey())
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => ['id' => $member->getKey(), 'email' => $member->email],
            ]);
    }

    /** @test */
    public function cross_business_user_access_is_blocked(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);

        $this->getJson($this->base($businessB).'/'.$memberA->getKey())->assertNotFound();
    }

    /** @test */
    public function missing_business_returns_404(): void
    {
        $this->asOwner();

        $this->getJson('/api/v1/platform/businesses/999999/users')->assertNotFound();
        $this->getJson('/api/v1/platform/businesses/999999/users/1')->assertNotFound();
    }

    /** @test */
    public function missing_user_returns_404(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();

        $this->getJson($this->base($business).'/999999')->assertNotFound();
    }
}
