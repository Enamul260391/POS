<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * P1-2 — regression guard for the seeded role matrix.
 *
 * The point of this suite is that enforcement did not change what any seeded
 * role can *do*. Before permissions were enforced the operational endpoints
 * accepted any active user, so a manager could run a till and an accountant
 * could record a purchase; after enforcement they can only do so through their
 * role's grants. Every expectation below is read off
 * {@see RolesAndPermissionsSeeder::roleGrants()} — the seeder is the source of
 * truth, not a guess about what these job titles "should" mean.
 *
 * The roles the seeder creates are admin, manager, salesman and accountant.
 * There is deliberately no `cashier` role: the till-facing seeded role is
 * `salesman`, and this suite tests the roles that actually exist.
 */
class SeededRoleRegressionTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /**
     * Seed roles + permissions now that at least one business exists.
     *
     * Called at the top of each test, not in `setUp()`: `RefreshDatabase` wraps
     * every test in a transaction, so a `setUp()` seed would be rolled back
     * before the test body ran and there would be no roles to assert against.
     */
    private function seedRoles(): void
    {
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);
    }

    /** Sign in as a user holding one of the seeded roles of this business. */
    private function actingAsSeededRole(Business $business, string $slug): User
    {
        $role = Role::query()
            ->where('business_id', $business->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $this->authenticate($user->fresh());

        return $user;
    }

    /** @test */
    public function the_seeder_creates_the_four_documented_roles(): void
    {
        Business::factory()->create();
        $this->seedRoles();

        $this->assertSame(4, Role::query()->distinct()->count('slug'), 'Exactly the four documented roles.');
        $this->assertDatabaseMissing('roles', ['slug' => 'cashier']);
    }

    /** @test */
    public function the_seeder_is_idempotent_and_creates_no_duplicates(): void
    {
        Business::factory()->create();
        $this->seedRoles();

        $counts = fn (): array => [
            'permissions' => DB::table('permissions')->count(),
            'pivot' => DB::table('permission_role')->count(),
        ];

        $first = $counts();

        // Re-running must be a no-op, not a duplicate-fest: the `permissions.slug`
        // and `permission_role(permission_id, role_id)` unique indexes would
        // throw if the seeder inserted unconditionally.
        $this->seedRoles();
        $this->seedRoles();

        $this->assertSame($first, $counts(), 'Re-seeding must not add permissions or pivot rows.');
    }

    /** @test */
    public function admin_retains_every_capability(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $user = $this->actingAsSeededRole($business, 'admin');

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $this->assertTrue(
                $user->hasPermission($capability),
                "The seeded admin role must hold {$capability}.",
            );
        }

        // And it really can drive the API.
        $this->getJson('/api/v1/products')->assertStatus(200);
        $this->getJson('/api/v1/reports/dashboard')->assertStatus(200);
    }

    /** @test */
    public function manager_keeps_its_documented_operational_access(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $user = $this->actingAsSeededRole($business, 'manager');

        // "Manage products, sales, purchases, and reports" — the manager holds
        // the coarse `*.manage` row for each, which the alias map resolves to
        // every fine-grained capability for that resource.
        foreach ([
            PermissionService::PRODUCTS_VIEW,
            PermissionService::PRODUCTS_DELETE,
            PermissionService::SALES_CREATE,
            PermissionService::PURCHASES_UPDATE,
            PermissionService::REPORTS_VIEW,
        ] as $capability) {
            $this->assertTrue($user->hasPermission($capability), "Manager must hold {$capability}.");
        }

        $this->getJson('/api/v1/products')->assertStatus(200);
        $this->getJson('/api/v1/purchases')->assertStatus(200);
        $this->getJson('/api/v1/reports/sales')->assertStatus(200);
    }

    /** @test */
    public function salesman_keeps_sales_and_customer_access(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $this->actingAsSeededRole($business, 'salesman');

        // "Handle sales and customer management"
        $this->getJson('/api/v1/sales')->assertStatus(200);
        $this->getJson('/api/v1/customers')->assertStatus(200);
        $this->getJson('/api/v1/products')->assertStatus(200);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(404);
    }

    /** @test */
    public function accountant_keeps_purchase_expense_and_payment_access(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $user = $this->actingAsSeededRole($business, 'accountant');

        // "Manage purchases, expenses, and payments"
        $this->getJson('/api/v1/purchases')->assertStatus(200);
        $this->getJson('/api/v1/expenses')->assertStatus(200);
        $this->getJson('/api/v1/suppliers')->assertStatus(200);
        $this->getJson('/api/v1/reports/profit-loss')->assertStatus(200);

        // Recording a payment is explicitly granted to the accountant.
        $this->assertTrue(
            $user->hasPermission(PermissionService::SALES_PAYMENTS_CREATE),
            'The accountant must be able to record a sale payment.',
        );
    }

    /** @test */
    public function accountant_may_not_create_sales(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $this->actingAsSeededRole($business, 'accountant');

        // Taking payment is not the same authority as raising a sale. The
        // accountant's `sales.payments.create` grant must not leak into the
        // coarse `sales.manage` capability, or the role separation is fiction.
        $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(403);
    }

    /** @test */
    public function a_role_without_the_capability_is_refused(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();

        // The salesman has no purchasing or reporting authority.
        $this->actingAsSeededRole($business, 'salesman');
        $this->getJson('/api/v1/purchases')->assertStatus(403);
        $this->getJson('/api/v1/reports/profit-loss')->assertStatus(403);

        // The accountant has no staff or role administration.
        $this->actingAsSeededRole($business, 'accountant');
        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/roles')->assertStatus(403);
    }

    /** @test */
    public function a_seeded_role_never_grants_access_to_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $this->seedRoles();

        // A manager of business A, who then also holds business B's admin role.
        $roleA = Role::query()->where('business_id', $businessA->id)->where('slug', 'manager')->firstOrFail();
        $roleB = Role::query()->where('business_id', $businessB->id)->where('slug', 'admin')->firstOrFail();

        $user = User::factory()->create(['business_id' => $businessA->id]);
        $user->roles()->attach([$roleA->getKey(), $roleB->getKey()]);

        $fresh = $user->fresh();

        // The manager's own grants still work inside its own business...
        $this->assertTrue($fresh->hasPermission(PermissionService::PRODUCTS_VIEW));

        // ...but the foreign admin role must not upgrade it to anything the
        // manager did not already hold.
        $this->assertFalse(
            $fresh->hasPermission(PermissionService::ROLES_UPDATE),
            'A foreign admin role must not upgrade a business A user.',
        );
        $this->assertFalse(
            $fresh->hasPermission(PermissionService::USERS_CREATE),
            'A foreign admin role must not upgrade a business A user.',
        );
    }
}
