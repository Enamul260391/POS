<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Support\LoginThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
     * Only the `api` limiter lives here. It backs the `throttle:api` middleware
     * that `bootstrap/app.php` prepends to the api middleware group, and counts
     * *every* request keyed by the authenticated user when available, otherwise
     * by IP address (the login and health endpoints are public).
     *
     * The limits come from `config/rate-limiting.php` so they are tunable from
     * `.env` without editing PHP. They degrade to the documented defaults if a
     * value is missing or non-positive — a limit of zero would lock every
     * caller out permanently.
     *
     * There is deliberately **no** named login limiter here. The failed-sign-in
     * lockout is owned by {@see LoginThrottle} and applied in
     * `AuthController`, because it must count only failed attempts and be
     * cleared by a success — neither of which a `throttle:` middleware can do.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $maxAttempts = $this->positiveLimit('rate-limiting.api.max_attempts', 120);
            $decayMinutes = $this->positiveLimit('rate-limiting.api.decay_minutes', 1);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }

    /**
     * Read a rate limit from config, falling back when it is not usable.
     */
    private function positiveLimit(string $key, int $default): int
    {
        $value = (int) config($key, $default);

        return $value > 0 ? $value : $default;
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
     * {@see PermissionService::CAPABILITIES}, each of which resolves through
     * exactly one canonical permission slug. No capability accepts a coarse
     * `<resource>.manage` row any more, so a stale grant of one authorises
     * nothing. A denial surfaces as
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
