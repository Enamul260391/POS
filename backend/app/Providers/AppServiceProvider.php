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
     * Platform-level Gate abilities.
     *
     * These are **platform** authorisation concepts, not tenant capabilities.
     * They must never appear in {@see PermissionService::ALL_CAPABILITIES},
     * the `permissions` catalogue, roles, `permission_role` or
     * `user_permissions` — the Platform Owner is not a tenant and holds no
     * tenant permission. Keeping the names here (rather than in
     * `PermissionService`) is what makes that separation structural instead
     * of a convention someone has to remember.
     *
     * Step 2 defines the boundary only; the `platform.businesses.*` endpoints
     * themselves arrive with Business CRUD in Step 3.
     *
     * @var array<int, string>
     */
    public const PLATFORM_ABILITIES = [
        'platform.businesses.view',
        'platform.businesses.create',
        'platform.businesses.update',
        'platform.businesses.status',
        'platform.businesses.delete',
        'platform.businesses.admin.create',
        'platform.users.view',
        'platform.users.create',
        'platform.users.update',
        'platform.users.status',
        'platform.users.roles',
        'platform.users.delete',
    ];

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
        $this->configurePlatformGates();
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

    /**
     * Define the platform-level Gate abilities ({@see PLATFORM_ABILITIES}).
     *
     * Deliberately separate from {@see configurePermissionGates()}: those
     * abilities resolve through `PermissionService` (the tenant model), while
     * these resolve through the platform *identity* alone —
     * {@see User::isPlatformOwner()}. No database row, role or override can
     * ever satisfy or revoke them, which is what makes platform
     * authorisation deny-by-default and structurally incapable of leaking
     * into tenant authorization (or vice versa).
     *
     * For now every ability answers the same single question — "is this
     * caller the Platform Owner?" — because Business CRUD does not exist yet.
     * Step 3 keeps these definitions and attaches them to the platform
     * endpoints; it must NOT move them into `PermissionService`.
     */
    protected function configurePlatformGates(): void
    {
        foreach (self::PLATFORM_ABILITIES as $ability) {
            Gate::define(
                $ability,
                static fn (User $user): bool => $user->isPlatformOwner(),
            );
        }
    }
}
