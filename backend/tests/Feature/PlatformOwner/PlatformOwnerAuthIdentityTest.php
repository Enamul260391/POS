<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 2 — the Platform Owner as a first-class authentication identity.
 *
 * What this suite proves, all through the *existing* auth surface (no
 * separate login endpoint, no separate token system):
 *
 *  1. `GET /auth/me` represents the Platform Owner as
 *     `business_id = null` + `is_platform_owner = true`, and a business user
 *     as their business id + `is_platform_owner = false`.
 *  2. The Platform Owner signs in through `POST /auth/login` exactly like
 *     anyone else and receives an ordinary Sanctum bearer token.
 *  3. The single-active-login rule and the Sanctum bearer lifecycle are
 *     untouched for the new identity.
 *  4. The Platform Owner carries no tenant capabilities — the `capabilities`
 *     list stays empty because it is resolved by the unchanged tenant
 *     permission model, not by the platform identity.
 */
class PlatformOwnerAuthIdentityTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    /** @test */
    public function me_reports_the_platform_owner_as_a_null_business_platform_identity(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $this->authenticate($owner);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Authenticated user',
                'data' => [
                    'business_id' => null,
                    'is_platform_owner' => true,
                ],
            ]);

        // The identity carries no tenant authority: the platform flag comes
        // from `platform_role`, never from permission resolution.
        $this->assertSame([], $this->meField('capabilities'));
        $this->assertNull($owner->fresh()->business_id);
    }

    /** @test */
    public function me_reports_a_business_user_with_their_business_and_no_platform_identity(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->authenticate($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'business_id' => $business->id,
                    'is_platform_owner' => false,
                ],
            ]);
    }

    /** @test */
    public function platform_owner_signs_in_through_the_existing_login_endpoint(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token_type' => 'Bearer',
                    'user' => [
                        'business_id' => null,
                        'is_platform_owner' => true,
                    ],
                ],
            ]);

        // Login creates no business and attaches the owner to none.
        $this->assertNull($owner->fresh()->business_id);

        // The single-active-login rule is unchanged: one live token, exactly
        // as for any other account.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @test */
    public function platform_owner_receives_a_normal_sanctum_bearer_token(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        // The token authenticates the ordinary protected endpoints — the same
        // `auth:sanctum` + `active` stack every other principal uses.
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'business_id' => null,
                    'is_platform_owner' => true,
                ],
            ]);

        // A second sign-in revokes the first token — the existing
        // single-active-login behaviour, unchanged for the platform identity.
        $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** Read one field of the `GET /auth/me` payload for the current token. */
    private function meField(string $field): mixed
    {
        return $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json("data.{$field}");
    }
}