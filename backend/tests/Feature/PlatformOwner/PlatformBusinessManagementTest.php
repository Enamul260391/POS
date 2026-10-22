<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 3 — the Platform Owner's Business Management API.
 *
 * Endpoints under `/api/v1/platform/businesses`, each behind
 * `auth:sanctum` + `active` + `platform` and its own
 * `platform.businesses.*` Gate. Authorization is asserted before controller
 * behaviour throughout, so tenant/platform boundary failures are proven to
 * occur at the middleware/Gate layer — never inside the controller.
 */
class PlatformBusinessManagementTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    private const BASE = '/api/v1/platform/businesses';

    /** @test */
    public function unauthenticated_user_cannot_list_businesses(): void
    {
        $this->getJson(self::BASE)
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_business(): void
    {
        $this->postJson(self::BASE, ['name' => 'New Tenant'])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->assertDatabaseCount('businesses', 0);
    }

    /** @test */
    public function unauthenticated_user_cannot_view_business(): void
    {
        $business = Business::factory()->create();

        $this->getJson(self::BASE.'/'.$business->getKey())
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_update_business(): void
    {
        $business = Business::factory()->create();

        $this->putJson(self::BASE.'/'.$business->getKey(), ['name' => 'Renamed'])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_change_business_status(): void
    {
        $business = Business::factory()->create();

        $this->patchJson(self::BASE.'/'.$business->getKey().'/status', ['status' => 'inactive'])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_business(): void
    {
        $business = Business::factory()->create();

        $this->deleteJson(self::BASE.'/'.$business->getKey())
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->assertNotSoftDeleted('businesses', ['id' => $business->getKey()]);
    }

    /** @test */
    public function normal_business_user_cannot_list_platform_businesses(): void
    {
        $this->actingAsBusinessUser();

        $this->getJson(self::BASE)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** @test */
    public function normal_business_user_cannot_create_a_platform_business(): void
    {
        $this->actingAsBusinessUser();

        $this->postJson(self::BASE, ['name' => 'Sneaky Tenant'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertDatabaseMissing('businesses', ['name' => 'Sneaky Tenant']);
    }

    /** @test */
    public function normal_business_user_cannot_view_another_business(): void
    {
        $other = Business::factory()->create();
        $mine = Business::factory()->create();
        $this->actingAsBusinessUser($mine);

        foreach ([$other->getKey(), $mine->getKey()] as $id) {
            $this->getJson(self::BASE.'/'.$id)
                ->assertForbidden()
                ->assertJson(['success' => false, 'message' => 'Forbidden']);
        }
    }

    /** @test */
    public function normal_business_user_cannot_update_another_business(): void
    {
        $other = Business::factory()->create(['name' => 'Victim Ltd']);
        $this->actingAsBusinessUser();

        $this->putJson(self::BASE.'/'.$other->getKey(), ['name' => 'Hijacked Ltd'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertDatabaseHas('businesses', ['id' => $other->getKey(), 'name' => 'Victim Ltd']);
    }

    /** @test */
    public function normal_business_user_cannot_change_another_business_status(): void
    {
        $other = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->actingAsBusinessUser();

        $this->patchJson(self::BASE.'/'.$other->getKey().'/status', ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertSame(Business::STATUS_ACTIVE, $other->fresh()->status);
    }

    /** @test */
    public function normal_business_user_cannot_delete_another_business(): void
    {
        $other = Business::factory()->create();
        $this->actingAsBusinessUser();

        $this->deleteJson(self::BASE.'/'.$other->getKey())
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertNotSoftDeleted('businesses', ['id' => $other->getKey()]);
    }

    /** @test */
    public function platform_owner_can_list_businesses(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        Business::factory()->create(['name' => 'Alpha Ltd']);
        Business::factory()->create(['name' => 'Beta Ltd']);
        $trashed = Business::factory()->create(['name' => 'Gone Ltd']);
        $trashed->delete();

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Businesses retrieved successfully'])
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonFragment(['name' => 'Alpha Ltd'])
            ->assertJsonFragment(['name' => 'Beta Ltd'])
            ->assertJsonMissing(['name' => 'Gone Ltd']);
    }

    /** @test */
    public function platform_owner_list_is_paginated_with_metadata(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        Business::factory()->count(3)->create();
        User::factory()->create(['business_id' => Business::query()->firstOrFail()->getKey()]);

        $payload = $this->getJson(self::BASE.'?per_page=2')->assertOk()->json('data');

        $this->assertCount(2, $payload['items']);
        $this->assertSame(2, $payload['pagination']['per_page']);
        $this->assertSame(3, $payload['pagination']['total']);

        $totalUsers = 0;
        foreach ($payload['items'] as $item) {
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('users_count', $item);
            $this->assertArrayNotHasKey('settings', $item);
            $totalUsers += (int) $item['users_count'];
        }
        $totalUsers += (int) $this->getJson(self::BASE.'?per_page=2&page=2')->json('data.items.0.users_count');
        $this->assertSame(1, $totalUsers);
    }

    /** @test */
    public function platform_owner_can_filter_businesses_by_status(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        Business::factory()->create(['name' => 'Open Ltd', 'status' => Business::STATUS_ACTIVE]);
        Business::factory()->create(['name' => 'Shut Ltd', 'status' => Business::STATUS_INACTIVE]);

        $this->getJson(self::BASE.'?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonFragment(['name' => 'Shut Ltd'])
            ->assertJsonMissing(['name' => 'Open Ltd']);
    }

    /** @test */
    public function platform_owner_can_view_a_business(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['name' => 'View Me Ltd']);

        $this->getJson(self::BASE.'/'.$business->getKey())
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Business retrieved successfully',
                'data' => ['id' => $business->getKey(), 'name' => 'View Me Ltd'],
            ]);
    }

    /** @test */
    public function platform_owner_viewing_missing_or_deleted_business_gets_404(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create();
        $business->delete();

        $this->getJson(self::BASE.'/999999')->assertNotFound();
        $this->getJson(self::BASE.'/'.$business->getKey())->assertNotFound();
    }



    /** @test */
    public function platform_owner_can_create_a_business(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, [
            'name' => 'Fresh Tenant Ltd',
            'phone' => '01700000000',
            'email' => 'hello@freshtenant.test',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ])
            ->assertCreated()
            ->assertJson(['success' => true, 'message' => 'Business created successfully'])
            ->assertJsonPath('data.name', 'Fresh Tenant Ltd')
            ->assertJsonPath('data.status', Business::STATUS_ACTIVE);

        $this->assertDatabaseHas('businesses', ['name' => 'Fresh Tenant Ltd']);
    }

    /** @test */
    public function platform_owner_created_business_defaults_to_the_bangladesh_locale(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, ['name' => 'Default Locale Ltd'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.timezone', 'Asia/Dhaka');

        $this->assertDatabaseHas('businesses', [
            'name' => 'Default Locale Ltd',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ]);
    }

    /** @test */
    public function platform_owner_can_create_a_business_with_the_bangladesh_locale(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, [
            'name' => 'Dhaka Tenant Ltd',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.timezone', 'Asia/Dhaka');

        $this->assertDatabaseHas('businesses', [
            'name' => 'Dhaka Tenant Ltd',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ]);
    }

    /** @test */
    public function platform_owner_can_create_a_business_with_the_utc_timezone(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, [
            'name' => 'Utc Tenant Ltd',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.timezone', 'UTC');
    }

    /** @test */
    public function platform_business_creation_requires_a_name(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function platform_business_creation_rejects_an_invalid_status(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->postJson(self::BASE, ['name' => 'Bad Status Ltd', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseMissing('businesses', ['name' => 'Bad Status Ltd']);
    }

    /** @test */
    public function creating_a_business_does_not_change_platform_owner_business_id(): void
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        $this->postJson(self::BASE, ['name' => 'Detached Ltd'])->assertCreated();

        $this->assertNull($owner->business_id);
        $this->assertNull($owner->fresh()->business_id);
    }

    /** @test */
    public function platform_owner_remains_a_platform_identity_after_creating(): void
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        $this->postJson(self::BASE, ['name' => 'First Ltd'])->assertCreated();
        $this->postJson(self::BASE, ['name' => 'Second Ltd'])->assertCreated();

        $fresh = $owner->fresh();

        $this->assertTrue($fresh->isPlatformOwner());
        $this->assertSame(User::PLATFORM_ROLE_OWNER, $fresh->platform_role);
        $this->assertNull($fresh->business_id);
        $this->assertSame([], $this->getJson('/api/v1/auth/me')->json('data.capabilities'));
    }

    /** @test */
    public function created_businesses_remain_separate_tenants(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $firstId = $this->postJson(self::BASE, ['name' => 'Tenant One'])->assertCreated()->json('data.id');
        $secondId = $this->postJson(self::BASE, ['name' => 'Tenant Two'])->assertCreated()->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(0, User::query()->whereIn('business_id', [$firstId, $secondId])->count());

        $this->getJson(self::BASE.'/'.$firstId)->assertOk()->assertJsonPath('data.name', 'Tenant One');
        $this->getJson(self::BASE.'/'.$secondId)->assertOk()->assertJsonPath('data.name', 'Tenant Two');
        $this->getJson('/api/v1/platform')->assertOk();
    }

    /** @test */
    public function platform_owner_can_update_a_business(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['name' => 'Old Name Ltd']);

        $this->putJson(self::BASE.'/'.$business->getKey(), ['name' => 'New Name Ltd', 'phone' => '01800000000'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name Ltd')
            ->assertJsonPath('data.phone', '01800000000');

        $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'name' => 'New Name Ltd']);
    }

    /** @test */
    public function updating_a_business_does_not_overwrite_its_stored_locale(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create([
            'name' => 'Keeps Its Locale Ltd',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->putJson(self::BASE.'/'.$business->getKey(), ['name' => 'Renamed Ltd'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.timezone', 'Europe/Berlin');

        $this->assertDatabaseHas('businesses', [
            'id' => $business->getKey(),
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
        ]);
    }

    /** @test */
    public function platform_business_update_never_changes_primary_key_or_moves_users(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['name' => 'Stable Ltd']);
        $user = User::factory()->create(['business_id' => $business->getKey()]);

        $this->putJson(self::BASE.'/'.$business->getKey(), [
            'id' => $business->getKey() + 100,
            'name' => 'Still Stable Ltd',
        ])->assertOk();

        $this->assertSame($business->getKey(), (int) Business::query()->where('name', 'Still Stable Ltd')->value('id'));
        $this->assertSame($business->getKey(), (int) $user->fresh()->business_id);
    }

    /** @test */
    public function platform_owner_can_activate_and_deactivate_a_business(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->patchJson(self::BASE.'/'.$business->getKey().'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', Business::STATUS_INACTIVE);

        $this->assertSame(Business::STATUS_INACTIVE, $business->fresh()->status);

        $this->patchJson(self::BASE.'/'.$business->getKey().'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', Business::STATUS_ACTIVE);

        $this->assertSame(Business::STATUS_ACTIVE, $business->fresh()->status);
    }

    /** @test */
    public function platform_business_status_rejects_unknown_values(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->patchJson(self::BASE.'/'.$business->getKey().'/status', ['status' => 'suspended'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(Business::STATUS_ACTIVE, $business->fresh()->status);
    }

    /** @test */
    public function deactivating_a_business_does_not_delete_its_users(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $user = User::factory()->create(['business_id' => $business->getKey()]);

        $this->patchJson(self::BASE.'/'.$business->getKey().'/status', ['status' => 'inactive'])->assertOk();

        $this->assertSame(Business::STATUS_INACTIVE, $business->fresh()->status);
        $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'business_id' => $business->getKey()]);
    }

    /** @test */
    public function platform_owner_can_delete_an_empty_business(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create(['name' => 'Empty Ltd']);

        $this->deleteJson(self::BASE.'/'.$business->getKey())
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Business deleted successfully']);

        $this->assertSoftDeleted('businesses', ['id' => $business->getKey()]);
        $this->getJson(self::BASE.'/'.$business->getKey())->assertNotFound();
    }

    /** @test */
    public function platform_owner_cannot_delete_a_business_with_users(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->getKey()]);

        $this->deleteJson(self::BASE.'/'.$business->getKey())
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertNotSoftDeleted('businesses', ['id' => $business->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
    }

    /** @test */
    public function platform_owner_cannot_delete_a_business_with_tenant_data(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $business = Business::factory()->create();
        Category::factory()->create(['business_id' => $business->getKey()]);

        $this->deleteJson(self::BASE.'/'.$business->getKey())->assertStatus(409);

        $this->assertNotSoftDeleted('businesses', ['id' => $business->getKey()]);
    }

    /** @test */
    public function platform_owner_deleting_a_missing_business_gets_404(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    /** @test */
    public function platform_business_routes_carry_the_documented_middleware_stack(): void
    {
        $routes = [
            ['GET', 'api/v1/platform/businesses'],
            ['POST', 'api/v1/platform/businesses'],
            ['GET', 'api/v1/platform/businesses/{business}'],
            ['PUT', 'api/v1/platform/businesses/{business}'],
            ['DELETE', 'api/v1/platform/businesses/{business}'],
            ['PATCH', 'api/v1/platform/businesses/{business}/status'],
        ];

        foreach ($routes as [$method, $uri]) {
            $matched = null;

            foreach (Route::getRoutes() as $route) {
                if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                    $matched = $route->gatherMiddleware();
                    break;
                }
            }

            $this->assertNotNull($matched, "No route for {$method} {$uri}.");

            foreach (['auth:sanctum', 'active', 'platform'] as $expected) {
                $this->assertContains($expected, $matched, "{$method} {$uri} must run {$expected}.");
            }

            $this->assertNotContains('business', $matched, "{$method} {$uri} must not carry tenant boundary.");
        }
    }
}

