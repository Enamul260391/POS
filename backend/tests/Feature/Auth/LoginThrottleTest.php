<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The failed sign-in lockout on `POST /api/v1/auth/login`.
 *
 * These tests exist because the previous implementation used a `throttle:login`
 * middleware, which counts *every* request. A cashier signing in correctly
 * burned the same budget as one mistyping a password, and a correct password
 * after a few typos returned `429` — the `401`-then-`429` pair visible in the
 * browser console.
 *
 * The behaviour asserted here, in order of importance:
 *
 *  1. repeated wrong passwords are still throttled  (the defence is intact)
 *  2. successful sign-ins are NOT throttled          (the outage is fixed)
 *  3. a success clears an earlier failure streak    (the budget is restorable)
 *  4. e-mail rotation cannot escape the IP bucket    (guessing stays bounded)
 *  5. the `429` is the standard envelope + Retry-After
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
    }

    private function makeUser(string $email, string $status = User::STATUS_ACTIVE): User
    {
        return User::factory()->create([
            'business_id' => $this->business->id,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
        ]);
    }

    /**
     * POST /api/v1/auth/login as if from a given client address.
     *
     * `withServerVariables()` rather than a shared helper without it, because
     * the per-account bucket is keyed on the IP and several tests below must
     * prove two addresses do not share a budget.
     *
     * @param  array<string, string>  $server
     */
    private function attempt(string $email, string $password, array $server = [])
    {
        return $this->withServerVariables($server)
            ->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => $password,
            ]);
    }

    /** @param array<string, string> $address */
    private function ip(string $address): array
    {
        return ['REMOTE_ADDR' => $address];
    }

    // -----------------------------------------------------------------------
    // 1. The defence is intact.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * The core guarantee: 5 wrong passwords in the window, the 6th is refused
     * with a `429` and mints no token.
     */
    public function repeated_wrong_passwords_are_throttled(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')
                ->assertStatus(401)
                ->assertJsonPath('message', 'Invalid credentials');
        }

        $this->attempt('admin@test.com', 'wrong-password')
            ->assertStatus(429)
            ->assertJson([
                'success' => false,
                'errors' => [],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @test
     *
     * The lockout is checked *before* the password is verified, so a locked-out
     * caller is refused even when they finally type the correct password —
     * otherwise the throttle would only slow down an attacker willing to keep
     * guessing wrong.
     */
    public function a_correct_password_does_not_bypass_an_active_lockout(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('admin@test.com', self::PASSWORD)->assertStatus(429);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // -----------------------------------------------------------------------
    // 2. The outage is fixed.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * The regression this whole change exists for: 10 consecutive *successful*
     * sign-ins from one address. Under the old `throttle:login` middleware the
     * 6th returned `429` and the cashier could not open the till at all.
     */
    public function successful_logins_are_never_throttled(): void
    {
        $this->makeUser('admin@test.com');

        for ($login = 0; $login < 10; $login++) {
            $this->attempt('admin@test.com', self::PASSWORD)
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    /**
     * @test
     *
     * Signing in and out repeatedly is normal at a shared till (a reload, a
     * handover between cashiers). None of it may consume the failure budget.
     */
    public function repeated_sign_in_and_sign_out_cycles_are_never_throttled(): void
    {
        $this->makeUser('admin@test.com');

        for ($cycle = 0; $cycle < 8; $cycle++) {
            $token = $this->attempt('admin@test.com', self::PASSWORD)
                ->assertOk()
                ->json('data.token');

            // Laravel caches the resolved user for the lifetime of a test, so the
            // guard is dropped to make the logout authenticate from the token
            // just issued, exactly as a separate HTTP request would.
            Auth::forgetGuards();

            $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

            Auth::forgetGuards();
        }

        // Every cycle revoked and reissued, then revoked again on sign-out, so
        // nothing may be left behind.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // -----------------------------------------------------------------------
    // 3. A success restores the budget.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * Four typos, then the correct password, then more typos: the counter was
     * reset by the success. Without the reset the final attempt below would be
     * a `429` even though the correct password proved the earlier ones were a
     * mistyped human, not an attack.
     */
    public function a_successful_login_clears_the_failure_streak(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('admin@test.com', self::PASSWORD)->assertOk();

        // A full fresh allowance is available again.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(429);
    }

    /**
     * @test
     *
     * A payload that fails validation must still cost an attempt, so the login
     * endpoint cannot be probed for free with junk. The old `throttle:login`
     * middleware got this by running ahead of the form request; the controller
     * lockout does not see a `422` at all, so `LoginRequest` has to charge it
     * and re-check the lockout itself.
     */
    public function malformed_payloads_still_consume_the_budget(): void
    {
        // While within budget the client still sees its own validation error.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'not-an-email',
                'password' => '',
            ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Validation failed');
        }

        // Once spent, the endpoint stops answering `422` and starts refusing.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertStatus(429);
    }

    /**
     * @test
     *
     * A completely absent address is a single shared bucket rather than an
     * unbounded one — otherwise "send no email at all" would be a way around
     * the per-account key entirely.
     */
    public function payloads_with_no_address_share_one_bounded_bucket(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [])
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['email', 'password']]);
        }

        $this->postJson('/api/v1/auth/login', [])->assertStatus(429);
    }

    // -----------------------------------------------------------------------
    // 4. Guessing stays bounded.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * The per-account bucket alone is defeated by sending every guess with a
     * different address, so the per-IP bucket is the backstop: 20 failures from
     * one IP across 20 distinct e-mails are still refused.
     */
    public function rotating_emails_cannot_escape_the_per_ip_bucket(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->attempt("victim{$index}@test.com", 'wrong-password')
                ->assertStatus(401);
        }

        // A brand-new address would have had its own empty per-account bucket.
        $this->attempt('victim21@test.com', 'wrong-password')
            ->assertStatus(429);
    }

    /**
     * @test
     *
     * The two buckets are independent in the other direction too: an attacker
     * cannot burn a real cashier's allowance by hammering from another machine.
     */
    public function the_per_account_bucket_is_scoped_to_the_client_ip(): void
    {
        $this->makeUser('admin@test.com');

        // Lock out admin@test.com from the attacking address.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password', $this->ip('10.0.0.9'))
                ->assertStatus(401);
        }

        $this->attempt('admin@test.com', 'wrong-password', $this->ip('10.0.0.9'))
            ->assertStatus(429);

        // The real cashier, arriving from their own machine, is unaffected.
        $this->attempt('admin@test.com', self::PASSWORD, $this->ip('10.0.0.42'))
            ->assertOk();
    }

    /**
     * @test
     *
     * A suspended account's refusal is counted like any other failure, so it
     * cannot be used as a free "does this address exist" oracle that never
     * trips the limit.
     */
    public function a_suspended_account_cannot_probe_for_free(): void
    {
        $this->makeUser('suspended@test.com', User::STATUS_INACTIVE);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('suspended@test.com', self::PASSWORD)->assertStatus(401);
        }

        $this->attempt('suspended@test.com', self::PASSWORD)->assertStatus(429);
    }

    /**
     * @test
     *
     * `Admin@Test.com` and `admin@test.com` must share one budget; otherwise a
     * case variation is a free retry for anyone guessing an address.
     */
    public function the_account_key_is_case_insensitive(): void
    {
        $this->makeUser('admin@test.com');

        // Two pairs = four attempts, alternating case, all within the budget.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
            $this->attempt('ADMIN@TEST.COM', 'wrong-password')->assertStatus(401);
        }

        // The fifth is still allowed, which is only true if both spellings have
        // been drawing on one shared bucket rather than two separate ones.
        $this->attempt('Admin@Test.com', 'wrong-password')->assertStatus(401);

        // ...and the sixth trips it.
        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(429);
    }

    // -----------------------------------------------------------------------
    // 5. The response contract.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * The `429` must be the standard API envelope and must state how long the
     * caller has to wait — "Too many requests" tells a person at a till nothing
     * about whether to wait ten seconds or go home.
     */
    public function the_throttled_response_is_actionable(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $response = $this->attempt('admin@test.com', 'wrong-password')
            ->assertStatus(429)
            ->assertJson([
                'success' => false,
                'errors' => [],
            ]);

        $this->assertStringContainsString(
            'Too many failed sign-in attempts',
            (string) $response->json('message'),
        );
        $this->assertMatchesRegularExpression(
            '/Try again in \d+ seconds?\./',
            (string) $response->json('message'),
            'The 429 must tell the user how long to wait.',
        );

        $retryAfter = $response->headers->get('Retry-After');

        $this->assertNotNull($retryAfter, 'A 429 must carry a Retry-After header.');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    // -----------------------------------------------------------------------
    // 6. Tunability and the remaining flood guard.
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * Removing the `throttle:login` middleware must not leave the public login
     * endpoint unbounded: the `api` group's own flood guard still applies, and
     * it counts *every* request including successful ones.
     */
    public function the_api_flood_guard_still_bounds_the_login_endpoint(): void
    {
        config(['rate-limiting.api.max_attempts' => 3]);

        $this->makeUser('admin@test.com');

        for ($login = 0; $login < 3; $login++) {
            $this->attempt('admin@test.com', self::PASSWORD)->assertOk();
        }

        $this->attempt('admin@test.com', self::PASSWORD)->assertStatus(429);
    }

    /**
     * @test
     *
     * The limits are meant to be tunable from `.env` via
     * `config/rate-limiting.php`. Lowering them must take effect, so an
     * installation can tighten the lockout without editing PHP.
     */
    public function the_limits_are_configurable(): void
    {
        config([
            'rate-limiting.login.account.max_attempts' => 1,
            'rate-limiting.login.ip.max_attempts' => 100,
        ]);

        $this->makeUser('admin@test.com');

        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(429);
    }

    /**
     * @test
     *
     * A misconfigured limit of zero would lock every cashier out permanently,
     * so a non-positive value must fall back to the documented default rather
     * than being honoured literally.
     */
    public function a_non_positive_limit_falls_back_to_the_default(): void
    {
        config([
            'rate-limiting.login.account.max_attempts' => 0,
            'rate-limiting.login.ip.max_attempts' => 0,
        ]);

        $this->makeUser('admin@test.com');

        // Falls back to 5, so six attempts are still required to trip it.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(429);
    }

    /**
     * @test
     *
     * `RateLimiter::clear()` is what makes a success restorable. Proving the
     * controller's key derivation with an independently written key guards
     * against a silently mismatched bucket hiding a broken clear.
     */
    public function the_buckets_can_be_cleared_explicitly(): void
    {
        $this->makeUser('admin@test.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt('admin@test.com', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('admin@test.com', 'wrong-password')->assertStatus(429);

        RateLimiter::clear('login:account:'.sha1('admin@test.com|127.0.0.1'));
        RateLimiter::clear('login:ip:'.sha1('127.0.0.1'));

        $this->attempt('admin@test.com', self::PASSWORD)->assertOk();
    }
}
