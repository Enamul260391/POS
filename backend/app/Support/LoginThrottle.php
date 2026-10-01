<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Failed sign-in lockout for `POST /api/v1/auth/login`.
 *
 * This is deliberately **not** a `throttle:` middleware. Laravel's
 * {@see ThrottleRequests} counts *every* request
 * that passes through it, so a middleware-based login limiter charges a
 * successful sign-in against the same budget as a wrong password. On a till
 * that is a real outage: a cashier who signs out and back in, or who opens the
 * app twice after a reload, is throttled while typing the *correct* password.
 * The `401` then `429` pair seen in the browser console was exactly that.
 *
 * So the accounting is done here instead, where the outcome is known:
 *
 *   tooManyAttempts()  — checked *before* any password hashing
 *   recordFailure()    — called only when the credentials were refused
 *   recordSuccess()    — called on a successful sign-in; clears the buckets
 *
 * Two independent buckets are kept, because either one alone is insufficient:
 *
 *   account  e-mail + IP   bounds an online guessing run against one account,
 *                          without letting an attacker burn a real user's
 *                          budget from another machine.
 *   ip       IP alone      is the backstop that makes e-mail rotation useless.
 *                          A single per-account key is trivially defeated by
 *                          sending each guess with a different address.
 *
 * The two keys are evaluated independently — the *stricter* one wins — so
 * neither can mask the other. See `config/rate-limiting.php` for the numbers.
 *
 * Enforced by {@see AuthController} rather than by
 * middleware, which is what makes "only failures count" expressible at all.
 * A caller that bypasses the controller gains nothing: the endpoint mints no
 * token anywhere else.
 */
final class LoginThrottle
{
    /**
     * The `429` returned while a caller is locked out.
     *
     * Authored here rather than left to the generic `HttpException` path so the
     * message states the wait in seconds. "Too many requests" tells a person at
     * a till nothing about whether to wait ten seconds or go home.
     *
     * The seconds come from the cache itself, so the value is the real
     * remaining window rather than a guess, and they are repeated in a
     * `Retry-After` header for any non-browser client that reads one.
     */
    public static function tooManyAttemptsResponse(string $email, string $ip): JsonResponse
    {
        $seconds = max(1, RateLimiter::availableIn(self::accountKey($email, $ip)));

        $message = $seconds === 1
            ? 'Too many failed sign-in attempts. Try again in 1 second.'
            : "Too many failed sign-in attempts. Try again in {$seconds} seconds.";

        return ApiResponse::error($message, [], 429)
            ->header('Retry-After', (string) $seconds);
    }

    /**
     * Is this caller currently locked out?
     *
     * Checked before the password is hashed, so a locked-out caller cannot make
     * the server do bcrypt work — which would otherwise turn the lockout itself
     * into a CPU exhaustion vector.
     */
    public static function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::accountKey($email, $ip), self::accountMaxAttempts())
            || RateLimiter::tooManyAttempts(self::ipKey($ip), self::ipMaxAttempts());
    }

    /**
     * Record a refused sign-in against both buckets.
     *
     * Called for every kind of refusal — unknown e-mail, wrong password and a
     * suspended account alike — so the endpoint cannot be used to map which
     * addresses are registered: a guess costs an attempt whether or not the
     * account is real.
     */
    public static function recordFailure(string $email, string $ip): void
    {
        RateLimiter::hit(self::accountKey($email, $ip), self::accountDecaySeconds());
        RateLimiter::hit(self::ipKey($ip), self::ipDecaySeconds());
    }

    /**
     * Clear both buckets after a successful sign-in.
     *
     * The step a middleware limiter cannot perform. Without it, a cashier who
     * mistypes three times, signs in correctly, then mistypes once more is
     * already over the limit — the earlier failures outlive the success that
     * proved they were not an attack.
     */
    public static function recordSuccess(string $email, string $ip): void
    {
        RateLimiter::clear(self::accountKey($email, $ip));
        RateLimiter::clear(self::ipKey($ip));
    }

    /**
     * Cache key for the per-account bucket.
     *
     * The address is lower-cased and trimmed so `Admin@x.com` and
     * `admin@x.com` cannot occupy two separate budgets, and so a stray trailing
     * space in a pasted address does not hand the caller a fresh allowance. The
     * SHA-1 keeps the key a fixed length no matter how long an address is
     * submitted — the key is derived from unvalidated input.
     */
    private static function accountKey(string $email, string $ip): string
    {
        return 'login:account:'.sha1(strtolower(trim($email)).'|'.$ip);
    }

    /**
     * Cache key for the per-IP bucket, which spans every account from that IP.
     */
    private static function ipKey(string $ip): string
    {
        return 'login:ip:'.sha1($ip);
    }

    private static function accountMaxAttempts(): int
    {
        return self::positiveInt('rate-limiting.login.account.max_attempts', 5);
    }

    private static function accountDecaySeconds(): int
    {
        return self::positiveInt('rate-limiting.login.account.decay_seconds', 60);
    }

    private static function ipMaxAttempts(): int
    {
        return self::positiveInt('rate-limiting.login.ip.max_attempts', 20);
    }

    private static function ipDecaySeconds(): int
    {
        return self::positiveInt('rate-limiting.login.ip.decay_seconds', 60);
    }

    /**
     * Read a limit from config, falling back if it is somehow non-positive.
     *
     * A limit of zero would lock every caller out permanently, so a bad value
     * degrades to the documented default instead.
     */
    private static function positiveInt(string $key, int $default): int
    {
        $value = (int) config($key, $default);

        return $value > 0 ? $value : $default;
    }
}
