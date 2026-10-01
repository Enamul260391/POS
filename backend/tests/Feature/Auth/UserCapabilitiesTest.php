<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Covers the `capabilities` list `UserResource` publishes.
 *
 * The field exists so a client can tell what a signed-in user may open without
 * provoking a `403` from every module in turn. That makes two guarantees worth
 * asserting: the list must agree exactly with what the API enforces (a module the
 * client thinks is closed must really be refused), and it must not overstate — a
 * capability that is absent has to stay absent, or the UI would offer a control
 * the server will reject.
 */
class UserCapabilitiesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPermissions;
    use InteractsWithPosApi;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Capabilities Business',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    /** A fresh token for a user, so `/auth/me` can be called as that principal. */
    private function tokenFor(User $user): string
    {
        return $user->createToken('capabilities-test')->plainTextToken;
    }

    /** The capabilities `GET /auth/me` reports for the given token. */
    private function capabilitiesOf(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->json('data.capabilities');
    }

    /** @test */
    public function me_reports_a_capability_list_even_for_a_user_without_roles(): void
    {
        $token = $this->tokenFor($this->userWithoutPermissions($this->business->id));

        // Holding nothing is a legitimate state, and the list has to say so
        // rather than go missing: the client treats an absent key as "no access",
        // so it must never be confused with "the server did not answer".
        $this->assertSame([], $this->capabilitiesOf($token));
    }

    /** @test */
    public function me_reports_exactly_the_capabilities_the_api_enforces(): void
    {
        $user = $this->userWithPermissions(['users.manage'], $this->business->id);
        $token = $this->tokenFor($user);

        $capabilities = $this->capabilitiesOf($token);

        // `users.manage` is the coarse row that authorises the whole users
        // domain, so every verb of it resolves to a granted capability and the
        // client only ever has to test one name.
        $this->assertContains(PermissionService::USERS_VIEW, $capabilities);
        $this->assertContains(PermissionService::USERS_CREATE, $capabilities);
        $this->assertContains(PermissionService::USERS_UPDATE, $capabilities);
        $this->assertContains(PermissionService::USERS_ROLES_UPDATE, $capabilities);

        // What the list claims must match what the API will actually allow.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/users')
            ->assertStatus(200);
    }

    /** @test */
    public function a_capability_the_user_lacks_is_absent_and_the_module_is_refused(): void
    {
        // A user who may read the staff directory but not manage roles: the exact
        // combination behind the directory screen's role catalogue, and the one
        // that used to surface as a `Forbidden` banner over a working table.
        $user = $this->userWithPermissions(['users.manage'], $this->business->id);
        $token = $this->tokenFor($user);

        $capabilities = $this->capabilitiesOf($token);

        $this->assertContains(PermissionService::USERS_VIEW, $capabilities);

        // Neither catalogue may be claimed, so the client can hide both up front
        // rather than discovering the refusal at render time.
        $this->assertNotContains(PermissionService::ROLES_VIEW, $capabilities);
        $this->assertNotContains(PermissionService::PERMISSIONS_VIEW, $capabilities);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/roles')
            ->assertStatus(403);
    }

    /** @test */
    public function capabilities_are_never_widened_by_an_unrelated_coarse_grant(): void
    {
        // Holding one resource's `*.manage` row says nothing about another, so
        // the two must not bleed into each other.
        $user = $this->userWithPermissions(['purchases.manage'], $this->business->id);

        $capabilities = $this->capabilitiesOf($this->tokenFor($user));

        $this->assertContains(PermissionService::PURCHASES_VIEW, $capabilities);
        $this->assertNotContains(PermissionService::SALES_VIEW, $capabilities);
        $this->assertNotContains(PermissionService::USERS_VIEW, $capabilities);
        $this->assertNotContains(PermissionService::ROLES_VIEW, $capabilities);
    }

    /** @test */
    public function login_publishes_the_same_capabilities_as_me(): void
    {
        $user = $this->userWithPermissions(['users.manage'], $this->business->id);

        // `login` and `me` are the two responses a session is built from. If they
        // disagreed, the permission gate would change its verdict across a page
        // reload, so both are asserted from the same fixture.
        $fromLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(200)->json('data.user.capabilities');

        $this->assertContains(PermissionService::USERS_VIEW, $fromLogin);
        $this->assertSame($fromLogin, $this->capabilitiesOf($this->tokenFor($user)));
    }
}