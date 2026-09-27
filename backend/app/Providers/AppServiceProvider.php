<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Number of API requests allowed per minute, per user (or per IP address
     * for unauthenticated callers such as the login endpoint).
     *
     * Deliberately generous: it protects the API from runaway clients without
     * getting in the way of normal POS usage or local development. Per-tenant
     * or per-endpoint limits can be layered on in later phases.
     */
    protected const API_RATE_LIMIT_PER_MINUTE = 120;

    /**
     * Failed-login attempts allowed per minute, keyed by email *and* IP.
     *
     * The generic `api` limiter allows 120 requests a minute, which is far too
     * generous to slow a credential-stuffing run against a single account.
     * This limiter is deliberately tight and is applied only to
     * `POST /auth/login`; `throttle:login` is a separate limiter, so a caller
     * that trips it cannot lock out ordinary authenticated API traffic.
     *
     * The key combines the submitted address with the client IP so that one
     * attacker cannot exhaust a victim's quota, while a distributed attempt
     * from many addresses still has to clear the per-IP half of the key.
     */
    protected const LOGIN_RATE_LIMIT_PER_MINUTE = 5;

    /**
     * Length of the login throttle window in seconds.
     *
     * Long enough to make an online guessing attack impractical, short enough
     * that a legitimate user who fat-fingers their password a few times is not
     * locked out of the till for the rest of the shift.
     */
    protected const LOGIN_RATE_LIMIT_DECAY_SECONDS = 60;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePermissionGates();
    }

    /**
     * Register the rate limiters used by the application.
     *
     * The `api` limiter backs the `throttle:api` middleware that
     * `bootstrap/app.php` prepends to the api middleware group. Requests are
     * keyed by the authenticated user when available, otherwise by IP address
     * (the login and health endpoints are public).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(static::API_RATE_LIMIT_PER_MINUTE)
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // Credential-guessing protection for `POST /auth/login`, which is
        // unauthenticated and therefore only ever covered by the generous
        // `api` limit above. The key is the lower-cased submitted address plus
        // the client IP, so a burst of guesses is bounded per account *and* per
        // source, and the response is the standard `429` envelope rendered by
        // `ApiExceptionRenderer`.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinutes(static::LOGIN_RATE_LIMIT_DECAY_SECONDS, static::LOGIN_RATE_LIMIT_PER_MINUTE)
                ->by($email.'|'.$request->ip());
        });
    }

    /**
     * Expose every permission capability as a Gate ability.
     *
     * One definition point for the whole application: routes use Laravel's
     * built-in `can:` middleware, controllers and services can call
     * `$user->can('roles.update')`, and the {@see User::hasPermission()} helper
     * resolves through the same Gate. Nothing re-implements permission logic.
     *
     * The abilities are keyed by the capability names declared in
     * {@see PermissionService::CAPABILITIES}, which also maps each capability
     * onto the accepted permission slugs (the fine-grained name or the
     * pre-existing `<resource>.manage` row). A denial surfaces as
     * `403 Forbidden` through `ApiExceptionRenderer`, while
     * `auth:sanctum` — which runs first — still produces `401` for anonymous
     * callers.
     */
    protected function configurePermissionGates(): void
    {
        $permissions = $this->app->make(PermissionService::class);

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            Gate::define(
                $capability,
                static fn (User $user): bool => $permissions->grants($user, $capability),
            );
        }
    }
}
