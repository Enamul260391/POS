<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 4 — Platform business-user roles / delete / middleware / abilities.
 */
class PlatformBusinessUserRolesDeleteTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    private function base(Business $business): string
    {
        return '/api/v1/platform/businesses/'.$business->getKey().'/users';
    }

    private function roleFor(Business $business, string $slug): Role
    {
        return Role::create([
            'business_id' => $business->getKey(),
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function memberFor(Business $business, ?Role $role = null): User
    {
        $role ??= $this->roleFor($business, 'cashier-'.$business->getKey());

        $user = User::factory()->create([
            'business_id' => $business->getKey(),
            'status' => User::STATUS_ACTIVE,
        ]);
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
    public function valid_tenant_role_sync_works(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $old = $this->roleFor($business, 'old');
        $new = $this->roleFor($business, 'new');
        $member = $this->memberFor($business, $old);

        $this->putJson(
            $this->base($business).'/'.$member->getKey().'/roles',
            ['role_ids' => [$new->getKey()]]
        )->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$new->getKey()], $member->fresh()->roles()->pluck('roles.id')->all());
    }

    /** @test */
    public function cross_business_role_assignment_is_blocked(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);
        $foreign = $this->roleFor($businessB, 'foreign');

        $this->putJson(
            $this->base($businessA).'/'.$memberA->getKey().'/roles',
            ['role_ids' => [$foreign->getKey()]]
        )->assertStatus(422)->assertJsonValidationErrors(['role_ids.0']);

        $this->assertNotContains(
            $foreign->getKey(),
            $memberA->fresh()->roles()->pluck('roles.id')->all()
        );
    }

    /** @test */
    public function cross_business_user_role_sync_is_blocked(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);
        $roleB = $this->roleFor($businessB, 'roleb');

        $this->putJson(
            $this->base($businessB).'/'.$memberA->getKey().'/roles',
            ['role_ids' => [$roleB->getKey()]]
        )->assertNotFound();
    }

    /** @test */
    public function platform_roles_cannot_be_assigned_to_tenant_users(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        // There is no platform role concept assignable here: roles must be
        // owned by the route business, and no platform.* ability exists in
        // the tenant permission model.
        $this->assertNotContains('platform.users.roles', \App\Services\Permission\PermissionService::ALL_CAPABILITIES);
        $this->assertContains('platform.users.roles', AppServiceProvider::PLATFORM_ABILITIES);

        $this->putJson(
            $this->base($business).'/'.$member->getKey().'/roles',
            ['role_ids' => [999999]]
        )->assertStatus(422);
    }

    /** @test */
    public function platform_owner_cannot_be_created_through_role_sync(): void
    {
        $owner = $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $role = $this->roleFor($business, 'staff');

        $this->putJson(
            $this->base($business).'/'.$member->getKey().'/roles',
            ['role_ids' => [$role->getKey()]]
        )->assertOk();

        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
        $this->assertSame($owner->getKey(), User::where('platform_role', User::PLATFORM_ROLE_OWNER)->first()->getKey());
        $this->assertNull($member->fresh()->platform_role);
    }

    /** @test */
    public function unreferenced_user_can_be_deleted(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->deleteJson($this->base($business).'/'.$member->getKey())
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'User deleted successfully']);

        $this->assertDatabaseMissing('users', ['id' => $member->getKey()]);
    }

    /** @test */
    public function user_with_transactional_references_cannot_be_deleted(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        Sale::factory()->create([
            'business_id' => $business->getKey(),
            'cashier_id' => $member->getKey(),
        ]);

        $this->deleteJson($this->base($business).'/'.$member->getKey())
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    /** @test */
    public function cross_business_delete_is_blocked(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);

        $this->deleteJson($this->base($businessB).'/'.$memberA->getKey())->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $memberA->getKey()]);
    }

    /** @test */
    public function platform_owner_cannot_be_deleted_through_this_api(): void
    {
        $owner = $this->asOwner();
        $business = Business::factory()->create();

        // The owner belongs to no business, so it is never resolvable here.
        $this->deleteJson($this->base($business).'/'.$owner->getKey())->assertNotFound();
        $this->getJson($this->base($business).'/'.$owner->getKey())->assertNotFound();

        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function platform_user_routes_carry_the_documented_middleware_stack(): void
    {
        $routes = [
            ['GET', 'api/v1/platform/businesses/{business}/users'],
            ['POST', 'api/v1/platform/businesses/{business}/users'],
            ['GET', 'api/v1/platform/businesses/{business}/users/{user}'],
            ['PUT', 'api/v1/platform/businesses/{business}/users/{user}'],
            ['PATCH', 'api/v1/platform/businesses/{business}/users/{user}/status'],
            ['PUT', 'api/v1/platform/businesses/{business}/users/{user}/roles'],
            ['DELETE', 'api/v1/platform/businesses/{business}/users/{user}'],
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
