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

        // "Manage products, sales, purchases, and reports" — the manager is
        // granted the explicit fine-grained permission for each of those, and the
        // resolver turns each one into exactly the capability it names.
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
        // accountant's `sales.payments.create` grant must not leak into
        // `sales.create`, or the role separation is fiction.
        $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(403);
    }

    /**
     * @test
     *
     * The seeded role matrix, measured as *effective capabilities* rather than as
     * raw permission rows. The taxonomy changed under it (the 14 coarse rows are
     * gone, `inventory.adjust` and `cash_registers.transactions.create` are new),
     * so a raw row count would be measuring the wrong thing — what matters is that
     * a role can still do exactly what it could do before.
     *
     * `admin` is the complete canonical capability set by definition. The other
     * three are pinned to the counts verified against the seeder's grants.
     */
    public function the_seeded_roles_resolve_to_their_documented_capability_counts(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $service = app(PermissionService::class);

        $counts = [];

        foreach (['admin', 'manager', 'salesman', 'accountant'] as $slug) {
            $counts[$slug] = count($service->resolvedCapabilities($this->actingAsSeededRole($business, $slug)));
        }

        $this->assertSame(count(PermissionService::ALL_CAPABILITIES), $counts['admin']);
        $this->assertSame(44, $counts['manager']);
        $this->assertSame(23, $counts['salesman']);
        $this->assertSame(18, $counts['accountant']);
    }

    /** @test */
    public function the_accountant_capability_set_is_exactly_its_documented_domains(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();
        $service = app(PermissionService::class);

        $resolved = $service->resolvedCapabilities($this->actingAsSeededRole($business, 'accountant'));

        $expected = [
            PermissionService::PURCHASES_VIEW,
            PermissionService::PURCHASES_CREATE,
            PermissionService::PURCHASES_UPDATE,
            PermissionService::PURCHASES_DELETE,
            PermissionService::SUPPLIERS_VIEW,
            PermissionService::SUPPLIERS_CREATE,
            PermissionService::SUPPLIERS_UPDATE,
            PermissionService::SUPPLIERS_DELETE,
            PermissionService::EXPENSES_VIEW,
            PermissionService::EXPENSES_CREATE,
            PermissionService::EXPENSES_UPDATE,
            PermissionService::EXPENSES_DELETE,
            PermissionService::EXPENSE_CATEGORIES_VIEW,
            PermissionService::EXPENSE_CATEGORIES_CREATE,
            PermissionService::EXPENSE_CATEGORIES_UPDATE,
            PermissionService::EXPENSE_CATEGORIES_DELETE,
            PermissionService::SALES_PAYMENTS_CREATE,
            PermissionService::REPORTS_VIEW,
        ];

        sort($expected);
        $sorted = $resolved;
        sort($sorted);

        $this->assertSame($expected, $sorted);

        // 18, not 22: the coarse `expenses.manage` row used to alias
        // `expense_categories.*` as well as `expenses.*`, but the accountant also
        // held `expense_categories.manage` directly, so the union was always
        // these same 18. Counting the cross-domain alias on top of the dedicated
        // row would double-count four capabilities the role never had twice.
        $this->assertCount(18, $resolved);
    }

    /**
     * @test
     *
     * The two renamed permissions must be present under their canonical slugs. The
     * counts above would still add up if `inventory.adjust` were missing and the
     * stock-adjustment capability silently dropped out, because the manager holds
     * that permission and *only* that permission for the write.
     */
    public function the_seeded_roles_hold_the_canonical_action_permissions(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();

        $manager = $this->actingAsSeededRole($business, 'manager');

        $this->assertTrue(
            $manager->hasPermission(PermissionService::INVENTORY_MANAGE),
            'The manager must be able to adjust stock.',
        );
        $this->assertTrue(
            $manager->hasPermission(PermissionService::CASH_REGISTERS_MANAGE),
            'The manager must be able to record a cash movement.',
        );

        // The salesman works the till, so it holds the cash-movement permission —
        // and, as before, no stock-adjustment authority.
        $salesman = $this->actingAsSeededRole($business, 'salesman');

        $this->assertTrue($salesman->hasPermission(PermissionService::CASH_REGISTERS_MANAGE));
        $this->assertFalse($salesman->hasPermission(PermissionService::INVENTORY_MANAGE));

        // The accountant takes payment but cannot raise a sale, and that
        // separation is a real constraint rather than a side effect of naming.
        $accountant = $this->actingAsSeededRole($business, 'accountant');

        $this->assertTrue($accountant->hasPermission(PermissionService::SALES_PAYMENTS_CREATE));
        $this->assertFalse($accountant->hasPermission(PermissionService::SALES_CREATE));
    }

    /**
     * @test
     *
     * A seeded role is expressed entirely in canonical fine-grained permissions.
     * If a coarse row ever crept back into a role grant, it would be inert — so
     * this asserts the role's raw slugs are all real catalogue entries, which
     * would catch the regression before the cleanup migration removed the row and
     * hid it.
     */
    public function no_seeded_role_is_granted_a_retired_coarse_permission(): void
    {
        $business = Business::factory()->create();
        $this->seedRoles();

        $retired = [
            'products.manage', 'sales.manage', 'purchases.manage', 'customers.manage',
            'suppliers.manage', 'expenses.manage', 'users.manage', 'roles.manage',
            'settings.manage', 'inventory.manage', 'cash_registers.manage',
            'categories.manage', 'sale_returns.manage', 'expense_categories.manage',
        ];

        foreach (['admin', 'manager', 'salesman', 'accountant'] as $slug) {
            $role = Role::query()
                ->where('business_id', $business->id)
                ->where('slug', $slug)
                ->firstOrFail();

            $granted = $role->permissions()->pluck('permissions.slug');

            $this->assertSame(
                [],
                array_values(array_intersect($retired, $granted->all())),
                "The {$slug} role must not hold a retired coarse permission.",
            );
        }
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
