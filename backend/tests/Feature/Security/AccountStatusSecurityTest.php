<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 26 — authentication hardening.
 *
 * Covers the three controls added during the security phase:
 *
 *  1. A **suspended** account (`users.status = 'inactive'`) cannot obtain a
 *     token, and the refusal is indistinguishable from a wrong password so the
 *     endpoint cannot be used to enumerate registered addresses.
 *  2. Suspension takes effect for a session that **already exists** — a token
 *     minted before the account was disabled stops working and is revoked,
 *     rather than surviving until it expires.
 *  3. `POST /auth/login` is **rate limited** per account+IP, so a burst of
 *     guesses is answered with `429`.
 */
class AccountStatusSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
    }

    /** A user in the test business with a known password. */
    private function makeUser(string $email = 'cashier@example.com', string $status = User::STATUS_ACTIVE): User
    {
        return User::factory()->create([
            'business_id' => $this->business->id,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
        ]);
    }

    /** POST /auth/login using the fixture's password. */
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }

    /** @test */
    public function active_user_can_still_log_in(): void
    {
        $this->login($this->makeUser())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);
    }

    /** @test */
    public function suspended_user_cannot_obtain_a_token(): void
    {
        $user = $this->makeUser('suspended@example.com', User::STATUS_INACTIVE);

        $this->login($user)->assertStatus(401);

        // No token row may have been created for the refused attempt.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * @test
     *
     * The refusal must be byte-identical to a wrong password, otherwise a
     * caller can tell "this account exists but is disabled" from "no such
     * account" and enumerate the user base.
     */
    public function suspended_user_is_indistinguishable_from_a_wrong_password(): void
    {
        $suspended = $this->makeUser('suspended@example.com', User::STATUS_INACTIVE);

        $disabled = $this->login($suspended);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $suspended->email,
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => self::PASSWORD,
        ]);

        foreach ([$wrongPassword, $unknownEmail] as $response) {
            $response->assertStatus(401);

            $this->assertSame($disabled->json(), $response->json());
        }
    }

    /**
     * @test
     *
     * Suspension must revoke a session that was established while the account
     * was still active — `auth:sanctum` alone only proves the token is valid.
     */
    public function suspending_an_account_revokes_its_existing_session(): void
    {
        $user = $this->makeUser();
        $token = $this->login($user)->assertOk()->json('data.token');

        // The session works while the account is active.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        // Suspend the account without touching the token.
        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        // The presented token was deleted, so it cannot be replayed even if the
        // account were reactivated afterwards.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * @test
     *
     * The suspended check guards every protected route, not just `/auth/me`.
     */
    public function suspending_an_account_blocks_data_endpoints_too(): void
    {
        $user = $this->makeUser();
        $token = $this->login($user)->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/products')->assertOk();

        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->withToken($token)->getJson('/api/v1/products')->assertStatus(401);
    }

    /** @test */
    public function reactivating_an_account_restores_access(): void
    {
        $user = $this->makeUser();
        $token = $this->login($user)->assertOk()->json('data.token');

        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        // The middleware revoked the token, so the user must sign in again.
        $user->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->login($user)->assertOk();
    }

    /**
     * @test
     *
     * Repeated guesses for one account are throttled with 429. The limiter is
     * keyed by email+IP, so a different account from the same address is not
     * penalised by this one.
     */
    public function login_is_rate_limited_per_account_and_ip(): void
    {
        $this->makeUser('target@example.com');

        // Five attempts are allowed per minute; the sixth is refused.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * @test
     *
     * Throttling must not become a denial-of-service against a legitimate
     * user: exhausting one account's budget leaves another account reachable.
     */
    public function rate_limit_on_one_account_does_not_lock_out_another(): void
    {
        $this->makeUser('target@example.com');
        $other = $this->makeUser('other@example.com');

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->login($other)->assertOk();
    }

    /**
     * @test
     *
     * The limiter counts attempts before validation, so a malformed payload
     * cannot be used to probe the endpoint for free.
     */
    public function malformed_login_payloads_still_consume_the_rate_limit(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'not-an-email',
                'password' => '',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertStatus(429);
    }
}
