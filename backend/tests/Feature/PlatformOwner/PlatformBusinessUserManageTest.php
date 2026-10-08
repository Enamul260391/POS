<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 4 — Platform business-user update / status / roles / delete.
 */
class PlatformBusinessUserManageTest extends TestCase
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

    private function asOwner(): User
    {
        $owner = User::factory()->platformOwner()->create();
        $this->authenticate($owner);

        return $owner;
    }

    /** @test */
    public function platform_owner_can_update_allowed_profile_fields(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->putJson($this->base($business).'/'.$member->getKey(), [
            'name' => 'Renamed Member',
            'phone' => '01800000002',
            'branch' => 'Branch 2',
            'notes' => 'Updated by platform.',
        ])->assertOk()->assertJson(['success' => true, 'message' => 'User updated successfully']);

        $this->assertDatabaseHas('users', [
            'id' => $member->getKey(),
            'name' => 'Renamed Member',
            'phone' => '01800000002',
            'branch' => 'Branch 2',
        ]);
    }

    /** @test */
    public function user_business_id_cannot_be_changed(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $member = $this->memberFor($businessA);

        $this->putJson($this->base($businessA).'/'.$member->getKey(), [
            'business_id' => $businessB->getKey(),
        ])->assertStatus(422);

        $this->assertSame($businessA->getKey(), $member->fresh()->business_id);
    }

    /** @test */
    public function sensitive_fields_cannot_be_mass_assigned(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        foreach ([
            ['id' => 999999],
            ['platform_role' => 'owner'],
            ['is_platform_owner' => true],
            ['status' => User::STATUS_INACTIVE],
        ] as $payload) {
            $this->putJson($this->base($business).'/'.$member->getKey(), $payload)
                ->assertStatus(422);
        }

        $fresh = $member->fresh();
        $this->assertSame($member->getKey(), $fresh->getKey());
        $this->assertNull($fresh->platform_role);
        $this->assertSame(User::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, User::where('platform_role', User::PLATFORM_ROLE_OWNER)->count());
    }

    /** @test */
    public function cross_business_update_is_blocked(): void
    {
        $this->asOwner();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $memberA = $this->memberFor($businessA);

        $this->putJson($this->base($businessB).'/'.$memberA->getKey(), ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertNotSame('Hacked', $memberA->fresh()->name);
    }

    /** @test */
    public function platform_owner_can_deactivate_a_tenant_user(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_INACTIVE]
        )->assertOk()->assertJson(['success' => true]);

        $this->assertSame(User::STATUS_INACTIVE, $member->fresh()->status);
        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    /** @test */
    public function platform_owner_can_reactivate_a_tenant_user(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);
        $member->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_ACTIVE]
        )->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $member->fresh()->status);
    }

    /** @test */
    public function invalid_status_is_rejected(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => 'suspended']
        )->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->assertSame(User::STATUS_ACTIVE, $member->fresh()->status);
    }

    /** @test */
    public function deactivation_does_not_delete_the_user(): void
    {
        $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_INACTIVE]
        )->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $member->getKey(),
            'business_id' => $business->getKey(),
        ]);
    }

    /** @test */
    public function inactive_user_is_blocked_by_active_middleware(): void
    {
        $owner = $this->asOwner();
        $business = Business::factory()->create();
        $member = $this->memberFor($business);

        $memberHeaders = $this->headersFor($member);

        // Per-call headers win over the default owner token (see
        // InteractsWithPosApi::headersFor), so the member proves its own
        // session without replacing the owner default for later calls.
        // Auth guards are resolved once per test and cached, so the guard
        // cache is dropped after every identity switch — otherwise the
        // member stays cached and the owner PATCH below 403s.
        $this->getJson('/api/v1/auth/me', $memberHeaders)->assertOk();
        Auth::forgetGuards();

        $this->patchJson(
            $this->base($business).'/'.$member->getKey().'/status',
            ['status' => User::STATUS_INACTIVE]
        )->assertOk();
        Auth::forgetGuards();

        // Existing `active` middleware revokes the token and answers 401.
        $this->getJson('/api/v1/auth/me', $memberHeaders)->assertStatus(401);
        Auth::forgetGuards();
        $this->assertSame($owner->getKey(), User::where('platform_role', User::PLATFORM_ROLE_OWNER)->first()->getKey());
    }
}
