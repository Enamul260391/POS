<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Single active login per user.
 *
 * An account may be signed in from one PC/till at a time. A new successful
 * login revokes the tokens the account already held, so the previous device is
 * rejected with `401` on its very next request while the new device keeps
 * working.
 *
 * Every assertion here goes through the real HTTP authentication layer —
 * `POST /auth/login` to mint a token and `GET /auth/me` (behind `auth:sanctum`
 * + `active`) to prove it is accepted or refused. Database assertions support
 * that, but never replace it: "a row was deleted" is not proof that a token
 * stopped authenticating, and vice versa.
 */
class SingleLoginTest extends TestCase
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
    private function makeUser(string $email, string $status = User::STATUS_ACTIVE): User
    {
        return User::factory()->create([
            'business_id' => $this->business->id,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
        ]);
    }

    /** POST /auth/login as if from a device, returning the raw response. */
    private function loginFromDevice(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }

    /** Log in and return the bearer token handed to that device. */
    private function tokenFromDevice(User $user): string
    {
        return $this->loginFromDevice($user)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.token');
    }

    /**
     * Call a protected endpoint as the holder of a given token.
     *
     * `Auth::forgetGuards()` drops the guard cache so the next request
     * re-authenticates from the presented bearer token, exactly as a separate
     * HTTP request from a separate device would.
     */
    private function meWithToken(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/auth/me');
    }

    /**
     * The persisted row backing a plaintext token, or `null` when the token has
     * been revoked.
     *
     * Uses Sanctum's own resolver so the test never re-implements the
     * `id|secret` hashing scheme.
     */
    private function storedTokenFor(string $plainTextToken): ?PersonalAccessToken
    {
        return PersonalAccessToken::findToken($plainTextToken);
    }

    /** @test */
    public function first_login_issues_a_working_token(): void
    {
        $user = $this->makeUser('admin@test.com');

        $token = $this->tokenFromDevice($user);

        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->meWithToken($token)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'admin@test.com');
    }

    /**
     * @test
     *
     * The core regression: signing in from a second PC revokes the first PC's
     * token, which is then refused by the real auth layer, while the new device
     * stays authenticated.
     */
    public function second_login_invalidates_the_first_device_token(): void
    {
        $user = $this->makeUser('admin@test.com');

        // PC-1.
        $tokenA = $this->tokenFromDevice($user);
        $this->meWithToken($tokenA)->assertOk();

        // PC-2 — the new login succeeds and replaces the old session.
        $tokenB = $this->tokenFromDevice($user);
        $this->assertNotSame($tokenA, $tokenB);

        // PC-1 is locked out on its next request.
        $this->meWithToken($tokenA)
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');

        // PC-2 keeps working.
        $this->meWithToken($tokenB)
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@test.com');

        // Exactly one live token remains — the old row was deleted, not merely
        // shadowed by a second one.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * @test
     *
     * Revocation is not limited to the immediately previous session: a third
     * login also leaves only the newest device valid.
     */
    public function a_third_login_also_leaves_only_the_newest_token_valid(): void
    {
        $user = $this->makeUser('admin@test.com');

        $tokenA = $this->tokenFromDevice($user);
        $tokenB = $this->tokenFromDevice($user);
        $tokenC = $this->tokenFromDevice($user);

        $this->meWithToken($tokenA)->assertStatus(401);
        $this->meWithToken($tokenB)->assertStatus(401);
        $this->meWithToken($tokenC)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @test */
    public function logout_only_revokes_the_token_that_called_it(): void
    {
        $user = $this->makeUser('admin@test.com');

        $token = $this->tokenFromDevice($user);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful');

        $this->meWithToken($token)->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @test
     *
     * The rule is scoped to the signing-in account: another user's session —
     * including a colleague inside the same business — is never touched.
     */
    public function a_second_user_is_unaffected_when_someone_else_logs_in_again(): void
    {
        $userA = $this->makeUser('user-a@test.com');
        $userB = $this->makeUser('user-b@test.com');

        $tokenA = $this->tokenFromDevice($userA);
        $tokenB = $this->tokenFromDevice($userB);

        // User A signs in again from a new PC.
        $tokenA2 = $this->tokenFromDevice($userA);

        // User A's old token is dead...
        $this->meWithToken($tokenA)->assertStatus(401);
        // ...the new one works...
        $this->meWithToken($tokenA2)->assertOk();

        // ...and user B is entirely unaffected, in the same business.
        $this->meWithToken($tokenB)
            ->assertOk()
            ->assertJsonPath('data.email', 'user-b@test.com');

        // The second login replaced user A's row instead of adding to it, and
        // user B's row is untouched.
        $this->assertNotNull($this->storedTokenFor($tokenA2));
        $this->assertNotNull($this->storedTokenFor($tokenB));
        $this->assertNull($this->storedTokenFor($tokenA));

        // One token per account, two accounts.
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    /**
     * @test
     *
     * The rule keys on the authenticated account, not on the tenant, the email
     * domain, the IP or the device: two users sharing a business keep
     * independent sessions.
     */
    public function the_rule_is_scoped_to_the_account_not_the_business(): void
    {
        $cashier = $this->makeUser('cashier@test.com');
        $manager = $this->makeUser('manager@test.com');

        $this->assertSame((int) $cashier->business_id, (int) $manager->business_id);

        $cashierToken = $this->tokenFromDevice($cashier);
        $managerToken = $this->tokenFromDevice($manager);

        $this->meWithToken($cashierToken)->assertOk();
        $this->meWithToken($managerToken)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    /**
     * @test
     *
     * A refused login must not disturb anything. The active-account check runs
     * before the revocation, so a suspended or mistyped attempt cannot be used
     * to knock a live session offline — otherwise single-login would double as
     * a denial of service against whoever is currently signed in.
     */
    public function a_refused_login_leaves_existing_sessions_untouched(): void
    {
        $user = $this->makeUser('admin@test.com');
        $token = $this->tokenFromDevice($user);

        // Wrong password.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $this->meWithToken($token)->assertOk();

        // Suspended account.
        $suspended = $this->makeUser('suspended@test.com', User::STATUS_INACTIVE);
        $this->loginFromDevice($suspended)->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * @test
     *
     * The pre-existing account-status security is unchanged: a suspended user
     * cannot mint a token, and a session established before the suspension is
     * revoked and rejected.
     */
    public function suspended_accounts_remain_blocked(): void
    {
        $user = $this->makeUser('admin@test.com');
        $token = $this->tokenFromDevice($user);
        $this->meWithToken($token)->assertOk();

        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->meWithToken($token)->assertStatus(401);

        // The suspension is still not distinguishable from a wrong password,
        // and still mints no token of its own.
        $disabled = $this->loginFromDevice($user);
        $disabled->assertStatus(401);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $this->assertSame($disabled->json(), $wrongPassword->json());

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * @test
     *
     * The login throttle still runs ahead of everything the service does, so
     * repeated logins stay capped per account+IP and the new revocation step
     * cannot turn the endpoint into an unlimited token minter.
     */
    public function login_rate_limiting_still_applies(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @test
     *
     * The token minted by a login keeps the project's existing naming and
     * ability conventions — no custom name or ability set is introduced.
     */
    public function the_issued_token_uses_the_existing_naming_convention(): void
    {
        $user = $this->makeUser('admin@test.com');

        $token = $this->tokenFromDevice($user);

        $stored = $this->storedTokenFor($token);

        $this->assertNotNull($stored);
        $this->assertSame(AuthService::TOKEN_NAME, $stored->name);
        $this->assertSame(['*'], $stored->abilities);
        $this->assertTrue($stored->tokenable->is($user));
    }
}
