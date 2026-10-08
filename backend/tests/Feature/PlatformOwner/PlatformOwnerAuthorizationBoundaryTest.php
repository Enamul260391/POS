<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Permission\PermissionService;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 2 — the platform authorization boundary.
 *
 * Four areas, each asserted against the runtime system rather than a
 * restatement of it:
 *
 *  1. `EnsurePlatformOwner` (alias `platform`) lets only the Platform Owner
 *     through — and refuses unauthenticated callers outright.
 *  2. The `platform.businesses.*` Gates exist, pass for the Platform Owner
 *     and fail for business users, and live entirely outside the tenant
 *     permission model (`PermissionService`, `permissions`, roles).
 *  3. The tenant boundary: the Platform Owner is explicitly refused on
 *     operational routes *before* any controller or capability check runs,
 *     while business users keep their existing behaviour.
 *  4. Realtime: the Platform Owner cannot authorize a `business.{id}`
 *     channel, and the tenant rule is unchanged.
 */
class PlatformOwnerAuthorizationBoundaryTest extends TestCase
{
    use InteractsWithPosApi;
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // 1. EnsurePlatformOwner (alias `platform`)
    // ------------------------------------------------------------------

    /** @test */
    public function platform_owner_passes_the_platform_middleware(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        $this->getJson('/api/v1/platform')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Platform boundary foundation',
                'data' => ['foundation' => true],
            ]);
    }

    /** @test */
    public function a_business_user_is_rejected_by_the_platform_middleware(): void
    {
        // Even a user holding *every* tenant capability must be refused: the
        // boundary is identity, not permission.
        $this->actingAsBusinessUser();

        $this->getJson('/api/v1/platform')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Forbidden',
                'errors' => [],
            ]);
    }

    /** @test */
    public function an_unauthenticated_request_is_rejected_by_the_platform_middleware(): void
    {
        $this->getJson('/api/v1/platform')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
                'errors' => [],
            ]);
    }

    /** @test */
    public function the_platform_group_carries_the_documented_middleware_stack(): void
    {
        // Identity-first ordering: authenticate, then liveness, then the
        // platform boundary — no route-level capability check applies here.
        $this->assertSame(
            ['api', 'auth:sanctum', 'active', 'platform'],
            $this->middlewareFor('GET', 'api/v1/platform'),
            'The platform foundation route must run exactly the documented stack.',
        );
    }

    // ------------------------------------------------------------------
    // 2. Platform Gates
    // ------------------------------------------------------------------

    /** @test */
    public function platform_owner_passes_every_platform_gate(): void
    {
        $owner = User::factory()->platformOwner()->create();

        foreach (AppServiceProvider::PLATFORM_ABILITIES as $ability) {
            $this->assertTrue(Gate::has($ability), "Gate {$ability} must be defined.");
            $this->assertTrue(
                Gate::forUser($owner)->allows($ability),
                "The Platform Owner must pass {$ability}.",
            );
        }
    }

    /** @test */
    public function a_business_user_fails_every_platform_gate(): void
    {
        // Fully privileged tenant user: platform abilities must still fail,
        // proving they resolve through identity rather than permissions.
        $user = $this->actingAsBusinessUser();

        foreach (AppServiceProvider::PLATFORM_ABILITIES as $ability) {
            $this->assertFalse(
                Gate::forUser($user)->allows($ability),
                "A business user must never pass {$ability}.",
            );
        }
    }

    /** @test */
    public function platform_abilities_do_not_appear_in_the_tenant_permission_catalogue(): void
    {
        foreach (AppServiceProvider::PLATFORM_ABILITIES as $ability) {
            // Not tenant capabilities...
            $this->assertNotContains($ability, PermissionService::ALL_CAPABILITIES);
            $this->assertNotContains($ability, PermissionService::catalogueSlugs());
            $this->assertFalse(
                PermissionService::isKnown($ability),
                "{$ability} must not be a known tenant capability.",
            );

            // ...not rows in the global catalogue...
            $this->assertSame(
                0,
                Permission::query()->where('slug', $ability)->count(),
                "{$ability} must never be materialised as a tenant permission.",
            );
        }

        // ...and the Platform Owner itself holds no tenant authority at all:
        // no roles, no capabilities. The gates above pass on identity alone.
        $owner = User::factory()->platformOwner()->create();

        $this->assertSame(0, $owner->roles()->count());
        $this->assertSame(
            [],
            app(PermissionService::class)->resolvedCapabilities($owner),
        );
    }

    // ------------------------------------------------------------------
    // 3. Tenant boundary
    // ------------------------------------------------------------------

    /** @test */
    public function platform_owner_cannot_access_representative_tenant_operational_routes(): void
    {
        $this->authenticate(User::factory()->platformOwner()->create());

        // A Phase 20-23 read and write, plus Phase 24B routes: both
        // operational groups must refuse the platform identity with the
        // standard denial envelope.
        foreach ([
            ['GET', '/api/v1/categories'],
            ['POST', '/api/v1/categories'],
            ['GET', '/api/v1/roles'],
            ['GET', '/api/v1/settings'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, $method === 'POST' ? ['name' => 'Should never be created'] : [])
                ->assertForbidden()
                ->assertJson([
                    'success' => false,
                    'message' => 'Forbidden',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function the_tenant_denial_happens_before_the_controller_runs(): void
    {
        // A probe registered with the exact production boundary stack. The
        // flag can only flip if the pipeline reaches the handler, so a 403
        // plus an untouched flag proves the refusal happened in middleware —
        // before any controller or business logic could execute.
        $handlerReached = false;

        Route::middleware(['api', 'auth:sanctum', 'active', 'business'])->get(
            '/api/v1/__platform-boundary-test/tenant-probe',
            static function () use (&$handlerReached): JsonResponse {
                $handlerReached = true;

                return response()->json(['success' => true, 'message' => 'Reached', 'data' => null]);
            },
        );

        $this->authenticate(User::factory()->platformOwner()->create());

        $this->getJson('/api/v1/__platform-boundary-test/tenant-probe')->assertForbidden();

        $this->assertFalse(
            $handlerReached,
            'The handler must never execute for the Platform Owner.',
        );

        // Control: a business user reaches the handler, so the flag measures
        // reachability rather than always failing.
        $this->authenticate(User::factory()->create(['business_id' => Business::factory()->create()->id]));

        $this->getJson('/api/v1/__platform-boundary-test/tenant-probe')->assertOk();

        $this->assertTrue($handlerReached, 'A business user must reach the handler.');
    }

    /** @test */
    public function operational_routes_gather_the_business_boundary_middleware(): void
    {
        // Architectural proof on the *production* routes: middleware always
        // runs before the controller, so its presence on the real route table
        // is what guarantees the ordering the probe above demonstrates.
        foreach ([
            ['GET', 'api/v1/categories'],
            ['POST', 'api/v1/categories'],
            ['GET', 'api/v1/roles'],
            ['GET', 'api/v1/products'],
            ['GET', 'api/v1/settings'],
        ] as [$method, $uri]) {
            $this->assertContains(
                'business',
                $this->middlewareFor($method, $uri),
                "{$method} {$uri} must run the tenant boundary middleware.",
            );
        }

        // The auth identity endpoints deliberately stay outside it: both the
        // Platform Owner and business users must keep resolving their session.
        foreach ([['GET', 'api/v1/auth/me'], ['POST', 'api/v1/auth/logout']] as [$method, $uri]) {
            $this->assertNotContains(
                'business',
                $this->middlewareFor($method, $uri),
                "{$method} {$uri} is shared identity infrastructure, not a tenant route.",
            );
        }
    }

    /** @test */
    public function a_normal_business_user_keeps_accessing_the_representative_route(): void
    {
        // `actingAsBusinessUser()` grants every tenant capability, so this is
        // exactly the pre-Step-2 behaviour of the representative route.
        $this->actingAsBusinessUser();

        $this->getJson('/api/v1/categories')->assertOk();
    }

    // ------------------------------------------------------------------
    // 4. Realtime channels
    // ------------------------------------------------------------------

    /** @test */
    public function platform_owner_cannot_authorize_a_business_realtime_channel(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->platformOwner()->create();
        $tenant = User::factory()->create(['business_id' => $business->id]);

        // Neither a real channel nor the degenerate id-0 name: the refusal is
        // the explicit platform identity check, not null-coercion.
        $this->assertFalse($this->authorizesChannel($owner, $business->id));
        $this->assertFalse($this->authorizesChannel($owner, 0));

        // Regression: the tenant rule is exactly as before — own business
        // allowed, any other refused.
        $this->assertTrue($this->authorizesChannel($tenant, $business->id));
        $this->assertFalse($this->authorizesChannel($tenant, $business->id + 1));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The middleware stack a live route runs, exactly as Laravel resolves it.
     *
     * `gatherMiddleware()` expands group aliases, so the answer matches what
     * the request pipeline would execute — not what a fixture claims.
     *
     * @return array<int, string>
     */
    private function middlewareFor(string $method, string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }

        $this->fail("No route registered for {$method} {$uri}.");
    }

    /**
     * Run the registered `business.{id}` channel callback as the given user.
     *
     * Invoked directly because the callback — not the HTTP endpoint — is the
     * security boundary being asserted, mirroring
     * `Tests\Feature\Realtime\RealtimeBroadcastTest::authorizes()`.
     */
    private function authorizesChannel(User $user, int $businessId): bool
    {
        $callback = $this->app->make(BroadcastingFactory::class)
            ->connection()
            ->getChannels()
            ->get('business.{businessId}');

        $this->assertIsCallable($callback, 'The business channel must be registered.');

        return (bool) $callback($user, $businessId);
    }
}