<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 5 — the coarse `<resource>.manage` expansion migration.
 *
 * The migration is additive: for every `(role_id, coarse permission_id)` row it
 * inserts the fine-grained permissions that the coarse permission *already
 * implies* through `PermissionService::CAPABILITIES`, for that same role only.
 *
 * Three properties are asserted throughout:
 *
 *  1. **Correctness of the map** — every expansion target is the canonical slug
 *     of a capability the coarse slug already satisfied, so no role gains
 *     anything it could not already do (`sales.manage` -> `sales.payments.create`
 *     is the interesting case, and it is *preserved* rather than dropped).
 *  2. **Additivity and idempotency** — coarse rows survive, no duplicate
 *     `(permission_id, role_id)` pair is ever created, and `down()` cannot
 *     destroy a grant it did not make.
 *  3. **Tenant safety** — a role only ever expands its *own* coarse grant, never
 *     one belonging to a role in another business.
 *
 * ## Why this suite does not use the live `PermissionService` map
 *
 * Step 6 removed the coarse `<resource>.manage` aliases from
 * `PermissionService::CAPABILITIES` and from the seeder's catalogue, so
 * `PermissionService::slugsFor()` no longer reports a coarse slug for anything
 * and `RolesAndPermissionsSeeder` no longer creates the coarse rows at all.
 * This migration, however, exists precisely to upgrade an installation that
 * *does* still have them, and it must not be rewritten to match the new state.
 *
 * So the historical precondition is reconstructed here explicitly: the coarse
 * rows are created by this suite's own fixture, and the alias table they stood
 * for is frozen in {@see self::COARSE_ALIASES}. Reading the live service instead
 * would make the migration's test tautological (it would pass vacuously once the
 * aliases are gone) and would rewrite history — the migration is a record of what
 * the old model *was*.
 */
class CoarseManagePermissionExpansionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_01_200000_expand_coarse_manage_role_permissions.php';

    /** The 14 coarse permissions this step expands. */
    private const COARSE_SLUGS = [
        'products.manage',
        'sales.manage',
        'purchases.manage',
        'customers.manage',
        'suppliers.manage',
        'expenses.manage',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'inventory.manage',
        'cash_registers.manage',
        'categories.manage',
        'sale_returns.manage',
        'expense_categories.manage',
    ];

    /**
     * The pre-Step-6 alias table, coarse-first: coarse slug => capabilities.
     *
     * A frozen record of what `PermissionService::CAPABILITIES` said when this
     * migration was written, including the two cross-domain relationships the
     * migration deliberately did *not* materialise. Doubles as the list of coarse
     * rows {@see self::seedCatalogue()} must plant, so the fixture and the
     * assertion can never disagree about which permissions are coarse.
     *
     * The capability-first view used to resolve grants is
     * {@see self::LEGACY_CAPABILITY_SLUGS}.
     *
     * @var array<string, array<int, string>>
     */
    private const COARSE_ALIASES = [
        'products.manage' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
        ],
        'sales.manage' => [
            'sales.view', 'sales.create', 'sales.update', 'sales.delete',
            'sales.payments.create',
        ],
        'purchases.manage' => [
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
        ],
        'customers.manage' => [
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
        ],
        'suppliers.manage' => [
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
        ],
        'expenses.manage' => [
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            // Cross-domain: deliberately *not* expanded by the migration.
            'expense_categories.view', 'expense_categories.create',
            'expense_categories.update', 'expense_categories.delete',
        ],
        'users.manage' => [
            'users.view', 'users.create', 'users.update', 'users.roles.update',
        ],
        'roles.manage' => [
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            // Cross-domain: deliberately *not* expanded by the migration.
            'permissions.view', 'users.roles.update',
        ],
        'settings.manage' => ['settings.view', 'settings.update'],
        'inventory.manage' => ['inventory.view', 'inventory.manage'],
        'cash_registers.manage' => [
            'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
            'cash_registers.manage',
        ],
        'categories.manage' => [
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        ],
        'sale_returns.manage' => ['sale_returns.view', 'sale_returns.create'],
        'expense_categories.manage' => [
            'expense_categories.view', 'expense_categories.create',
            'expense_categories.update', 'expense_categories.delete',
        ],
    ];

    /**
     * The pre-Step-6 model, capability-first: capability => every slug that
     * satisfied it.
     *
     * A frozen copy of `PermissionService::CAPABILITIES` as it stood when this
     * migration was written — the identity entries plus every coarse alias,
     * including the two cross-domain relationships the migration deliberately did
     * *not* materialise. Every assertion about what a coarse grant authorised
     * before the migration is answered from here.
     *
     * {@see self::COARSE_ALIASES} is the per-coarse view of the same facts, kept
     * alongside it because most of these tests reason coarse-grant-first.
     *
     * @var array<string, array<int, string>>
     */
    private const LEGACY_CAPABILITY_SLUGS = [
        'roles.view' => ['roles.view', 'roles.manage'],
        'roles.create' => ['roles.create', 'roles.manage'],
        'roles.update' => ['roles.update', 'roles.manage'],
        'roles.delete' => ['roles.delete', 'roles.manage'],

        'permissions.view' => ['permissions.view', 'roles.manage'],
        'users.view' => ['users.view', 'users.manage'],
        'users.create' => ['users.create', 'users.manage'],
        'users.update' => ['users.update', 'users.manage'],
        'users.roles.update' => ['users.roles.update', 'users.manage', 'roles.manage'],
        'settings.view' => ['settings.view', 'settings.manage'],
        'settings.update' => ['settings.update', 'settings.manage'],

        'categories.view' => ['categories.view', 'categories.manage'],
        'categories.create' => ['categories.create', 'categories.manage'],
        'categories.update' => ['categories.update', 'categories.manage'],
        'categories.delete' => ['categories.delete', 'categories.manage'],

        'products.view' => ['products.view', 'products.manage'],
        'products.create' => ['products.create', 'products.manage'],
        'products.update' => ['products.update', 'products.manage'],
        'products.delete' => ['products.delete', 'products.manage'],

        'inventory.view' => ['inventory.view', 'inventory.manage'],
        // At this point in history the coarse row *was* the adjust capability.
        'inventory.manage' => ['inventory.manage', 'inventory.adjust'],

        'customers.view' => ['customers.view', 'customers.manage'],
        'customers.create' => ['customers.create', 'customers.manage'],
        'customers.update' => ['customers.update', 'customers.manage'],
        'customers.delete' => ['customers.delete', 'customers.manage'],

        'suppliers.view' => ['suppliers.view', 'suppliers.manage'],
        'suppliers.create' => ['suppliers.create', 'suppliers.manage'],
        'suppliers.update' => ['suppliers.update', 'suppliers.manage'],
        'suppliers.delete' => ['suppliers.delete', 'suppliers.manage'],

        'purchases.view' => ['purchases.view', 'purchases.manage'],
        'purchases.create' => ['purchases.create', 'purchases.manage'],
        'purchases.update' => ['purchases.update', 'purchases.manage'],
        'purchases.delete' => ['purchases.delete', 'purchases.manage'],

        'sales.view' => ['sales.view', 'sales.manage'],
        'sales.create' => ['sales.create', 'sales.manage'],
        'sales.update' => ['sales.update', 'sales.manage'],
        'sales.delete' => ['sales.delete', 'sales.manage'],
        'sales.payments.create' => ['sales.payments.create', 'sales.manage'],

        'sale_returns.view' => ['sale_returns.view', 'sale_returns.manage'],
        'sale_returns.create' => ['sale_returns.create', 'sale_returns.manage'],

        'expenses.view' => ['expenses.view', 'expenses.manage'],
        'expenses.create' => ['expenses.create', 'expenses.manage'],
        'expenses.update' => ['expenses.update', 'expenses.manage'],
        'expenses.delete' => ['expenses.delete', 'expenses.manage'],

        'expense_categories.view' => [
            'expense_categories.view', 'expense_categories.manage', 'expenses.manage',
        ],
        'expense_categories.create' => [
            'expense_categories.create', 'expense_categories.manage', 'expenses.manage',
        ],
        'expense_categories.update' => [
            'expense_categories.update', 'expense_categories.manage', 'expenses.manage',
        ],
        'expense_categories.delete' => [
            'expense_categories.delete', 'expense_categories.manage', 'expenses.manage',
        ],

        'cash_registers.view' => ['cash_registers.view', 'cash_registers.manage'],
        'cash_registers.create' => ['cash_registers.create', 'cash_registers.manage'],
        'cash_registers.update' => ['cash_registers.update', 'cash_registers.manage'],
        // At this point in history the coarse row *was* the cash-movement write.
        'cash_registers.manage' => ['cash_registers.manage', 'cash_registers.transactions.create'],

        'reports.view' => ['reports.view', 'reports.manage'],
    ];

    /**
     * Execute the migration's `up()` against the current test database.
     *
     * `require` (not `require_once`) is deliberate: each call re-evaluates the
     * file and yields a fresh anonymous migration instance, which is what lets
     * this suite run the real production code repeatedly to prove idempotency.
     *
     * `RefreshDatabase` has already applied every migration to the in-memory
     * SQLite database, but that ran against an empty catalogue, so `up()` is
     * still the operation under test here.
     */
    private function runMigration(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);

        $migration->up();
    }

    /**
     * Seed the catalogue the way an installation looked *before* Step 6.
     *
     * The current seeder no longer creates the 14 coarse rows, so this suite
     * creates them itself. That is not a workaround — it is the fixture the
     * migration is designed for: an installation upgrading from the coarse
     * catalogue. Role grants are left untouched.
     */
    private function seedCatalogue(): void
    {
        Business::factory()->create();
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        // `array_keys()`, not `COARSE_SLUGS`: the frozen alias table is the
        // authority on which permissions were coarse, and the two must not drift.
        foreach (array_keys(self::COARSE_ALIASES) as $slug) {
            Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucwords(str_replace('.', ' ', $slug))],
            );
        }
    }

    /**
     * Seed the catalogue but strip every role grant.
     *
     * `RolesAndPermissionsSeeder` hands the whole catalogue to each business's
     * `admin` role, so after seeding no installation is ever free of coarse
     * grants. Truncating `permission_role` afterwards is how this suite builds
     * the genuinely-grant-free starting states the "empty database" cases need.
     */
    private function seedCatalogueWithoutGrants(): void
    {
        $this->seedCatalogue();

        DB::table('permission_role')->delete();
    }

    /**
     * @test
     *
     * The two frozen views of the pre-Step-6 model must describe the same thing,
     * or this suite would be reasoning about a history that never existed.
     */
    public function the_frozen_pre_step_6_tables_agree_with_each_other(): void
    {
        $this->assertSame(
            self::COARSE_SLUGS,
            array_keys(self::COARSE_ALIASES),
            'The coarse permission list and the coarse-first alias table must list the same 14 slugs.',
        );

        $inverted = [];

        foreach (self::COARSE_ALIASES as $coarse => $capabilities) {
            foreach ($capabilities as $capability) {
                $inverted[$capability][] = $coarse;
            }
        }

        foreach ($inverted as $capability => $coarses) {
            // The capability-first table also lists each capability's own slug;
            // only its *coarse* entries should mirror the coarse-first table.
            $recorded = array_values(array_intersect(
                self::LEGACY_CAPABILITY_SLUGS[$capability] ?? [],
                self::COARSE_SLUGS,
            ));

            // Set equality, not order: the two tables are written in whatever
            // order reads best, and that is not a fact worth asserting on.
            sort($coarses);
            sort($recorded);

            $this->assertSame(
                $coarses,
                $recorded,
                "{$capability} lists different coarse aliases in the two frozen tables.",
            );
        }

        // Every capability named by the frozen tables is a real capability of the
        // current model — the names are API surface and were never renamed.
        foreach (array_keys(self::LEGACY_CAPABILITY_SLUGS) as $capability) {
            $this->assertContains(
                $capability,
                PermissionService::ALL_CAPABILITIES,
                "{$capability} is not a capability of the current model.",
            );
        }
    }

    /**
     * Create a role holding exactly the given slugs.
     *
     * @param  array<int, string>  $slugs
     */
    private function roleWith(Business $business, string $slug, array $slugs): Role
    {
        $role = Role::create([
            'business_id' => $business->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Coarse expansion fixture.',
        ]);

        $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();

        $role->permissions()->sync($ids);

        return $role;
    }

    /** @return array<int, string> The slugs currently granted to the role. */
    private function slugsOf(Role $role): array
    {
        return $role->permissions()->pluck('permissions.slug')->all();
    }

    /**
     * The capability set a set of role-held slugs granted *before* Step 6.
     *
     * Resolved through {@see self::LEGACY_CAPABILITY_SLUGS} — the frozen pre-Step-6
     * capability map — rather than through the live `PermissionService`, which no
     * longer recognises a coarse slug at all. This is what lets the suite compare
     * what a coarse grant implied *then* against what its materialised
     * fine-grained rows imply *now*.
     *
     * Role-derived only: user ALLOW/DENY overrides are deliberately excluded so
     * this measures exactly what Step 5 is allowed to affect.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function capabilitiesFor(array $slugs): array
    {
        $granted = [];

        foreach (self::LEGACY_CAPABILITY_SLUGS as $capability => $accepted) {
            if (array_intersect($accepted, $slugs) !== []) {
                $granted[] = $capability;
            }
        }

        sort($granted);

        return $granted;
    }

    /** @test */
    public function products_manage_expands_into_the_four_product_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'products-coarse', ['products.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['products.view', 'products.create', 'products.update', 'products.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "products.manage must expand into {$slug}.");
        }

        $this->assertContains('products.manage', $slugs, 'The coarse grant itself must survive.');
    }

    /** @test */
    public function sales_manage_expands_into_the_sales_capabilities_including_payment_creation(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'sales-coarse', ['sales.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach ([
            'sales.view',
            'sales.create',
            'sales.update',
            'sales.delete',
            // Explicitly preserved, not assumed: `PermissionService::CAPABILITIES`
            // registers `sales.manage` as an accepted alias for
            // `SALES_PAYMENTS_CREATE`, so this is existing documented behaviour.
            // Dropping it here would narrow the very roles this step protects.
            'sales.payments.create',
        ] as $slug) {
            $this->assertContains($slug, $slugs, "sales.manage must expand into {$slug}.");
        }

        $this->assertContains('sales.manage', $slugs);
    }

    /** @test */
    public function purchases_manage_expands_into_the_four_purchase_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'purchases-coarse', ['purchases.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "purchases.manage must expand into {$slug}.");
        }

        $this->assertContains('purchases.manage', $slugs);
    }

    /** @test */
    public function customers_manage_expands_into_the_four_customer_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'customers-coarse', ['customers.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['customers.view', 'customers.create', 'customers.update', 'customers.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "customers.manage must expand into {$slug}.");
        }

        $this->assertContains('customers.manage', $slugs);
    }

    /** @test */
    public function suppliers_manage_expands_into_the_four_supplier_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'suppliers-coarse', ['suppliers.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "suppliers.manage must expand into {$slug}.");
        }

        $this->assertContains('suppliers.manage', $slugs);
    }

    /** @test */
    public function expenses_manage_expands_into_the_four_expense_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'expenses-coarse', ['expenses.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "expenses.manage must expand into {$slug}.");
        }

        $this->assertContains('expenses.manage', $slugs);
    }

    /**
     * @test
     *
     * `expenses.manage` also aliases `expense_categories.*`. That cross-domain
     * relationship is real and load-bearing, but it is explicitly left to a
     * later alias-cleanup step, so this migration must not materialise it.
     */
    public function expenses_manage_does_not_pull_in_expense_category_permissions(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'expenses-alias-coarse', ['expenses.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach ([
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',
        ] as $slug) {
            $this->assertNotContains(
                $slug,
                $slugs,
                "{$slug} is aliased by expenses.manage but is a cross-domain relationship "
                    .'that this step deliberately does not migrate.',
            );
        }
    }

    /**
     * @test
     *
     * `users.manage` historically satisfied all four `users.*` capabilities, so
     * `users.roles.update` is expanded as part of its own domain rather than
     * treated as a separate concern.
     */
    public function users_manage_expands_into_the_four_user_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'users-coarse', ['users.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach ([
            'users.view',
            'users.create',
            'users.update',
            'users.roles.update',
        ] as $slug) {
            $this->assertContains($slug, $slugs, "users.manage must expand into {$slug}.");
        }

        $this->assertContains('users.manage', $slugs);
    }

    /** @test */
    public function roles_manage_expands_into_the_four_role_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'roles-coarse', ['roles.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['roles.view', 'roles.create', 'roles.update', 'roles.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "roles.manage must expand into {$slug}.");
        }

        $this->assertContains('roles.manage', $slugs);
    }

    /**
     * @test
     *
     * `roles.manage` also aliases `permissions.view` and `users.roles.update`.
     * Both are cross-domain, and `permissions.view` is specifically called out
     * by the audit as an existing alias that this step must not fold into the
     * role domain.
     */
    public function roles_manage_does_not_pull_in_permissions_view(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'roles-alias-coarse', ['roles.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        $this->assertNotContains(
            'permissions.view',
            $slugs,
            'permissions.view is aliased by roles.manage but is a cross-domain alias '
                .'reserved for the later alias-cleanup step.',
        );

        $this->assertNotContains(
            'users.roles.update',
            $slugs,
            'users.roles.update is reachable through roles.manage, but it belongs to the '
                .'users domain and is not the direct semantic equivalent of roles.manage.',
        );
    }

    /** @test */
    public function settings_manage_expands_into_view_and_update(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'settings-coarse', ['settings.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        $this->assertContains('settings.view', $slugs);
        $this->assertContains('settings.update', $slugs);
        $this->assertContains('settings.manage', $slugs);
    }

    /**
     * @test
     *
     * Inventory has no create/update/delete in the catalogue: a manual stock
     * adjustment *was* the coarse `inventory.manage` capability at this point in
     * history, so the sole expansion is `inventory.view`. (`inventory.adjust`
     * only became canonical in Step 6, which is after this migration.)
     */
    public function inventory_manage_expands_only_into_inventory_view(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'inventory-coarse', ['inventory.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        $this->assertContains('inventory.view', $slugs);
        $this->assertContains('inventory.manage', $slugs, 'The coarse row is the adjust capability itself.');

        foreach (['inventory.create', 'inventory.update', 'inventory.delete'] as $slug) {
            $this->assertNotContains($slug, $slugs, "{$slug} does not exist and must not be invented.");
        }
    }

    /** @test */
    public function cash_registers_manage_expands_only_into_the_three_existing_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'cash-coarse', ['cash_registers.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['cash_registers.view', 'cash_registers.create', 'cash_registers.update'] as $slug) {
            $this->assertContains($slug, $slugs, "cash_registers.manage must expand into {$slug}.");
        }

        $this->assertContains('cash_registers.manage', $slugs);

        foreach (['cash_registers.delete', 'cash_registers.transactions.create'] as $slug) {
            $this->assertNotContains($slug, $slugs, "{$slug} must not be created by this step.");
        }
    }

    /**
     * @test
     *
     * Step 5 must not invent a catalogue entry. `inventory.adjust` and
     * `cash_registers.transactions.create` are absent here because this migration
     * predates Step 6, when they were still dead aliases; they are created by the
     * seeder, not by an expansion. `reports.manage` remains dead and must stay out
     * of the catalogue entirely.
     */
    public function the_migration_never_creates_a_permission_row(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $catalogueBefore = DB::table('permissions')->pluck('slug')->all();

        $this->roleWith($business, 'no-new-rows-coarse', [
            'inventory.manage',
            'cash_registers.manage',
            'roles.manage',
        ]);

        $this->runMigration();

        $this->assertSame(
            $catalogueBefore,
            DB::table('permissions')->orderBy('slug')->pluck('slug')->all(),
            'An expansion is a grant change, never a catalogue change.',
        );

        $this->assertFalse(
            Permission::query()->where('slug', 'reports.manage')->exists(),
            'reports.manage was never a real permission and must stay out of the catalogue.',
        );
    }

    /** @test */
    public function categories_manage_expands_into_the_four_category_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'categories-coarse', ['categories.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach (['categories.view', 'categories.create', 'categories.update', 'categories.delete'] as $slug) {
            $this->assertContains($slug, $slugs, "categories.manage must expand into {$slug}.");
        }

        $this->assertContains('categories.manage', $slugs);
    }

    /** @test */
    public function sale_returns_manage_expands_only_into_view_and_create(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'sale-returns-coarse', ['sale_returns.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        $this->assertContains('sale_returns.view', $slugs);
        $this->assertContains('sale_returns.create', $slugs);
        $this->assertContains('sale_returns.manage', $slugs);

        foreach (['sale_returns.update', 'sale_returns.delete'] as $slug) {
            $this->assertNotContains($slug, $slugs, "{$slug} does not exist and must not be invented.");
        }
    }

    /** @test */
    public function expense_categories_manage_expands_into_the_four_expense_category_capabilities(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'expense-categories-coarse', ['expense_categories.manage']);

        $this->runMigration();

        $slugs = $this->slugsOf($role);

        foreach ([
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',
        ] as $slug) {
            $this->assertContains($slug, $slugs, "expense_categories.manage must expand into {$slug}.");
        }

        $this->assertContains('expense_categories.manage', $slugs);
    }

    // ------------------------------------------------------------------
    // Authorization equivalence
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * The central safety property: for every coarse permission, a role's
     * effective capability set is **identical** before and after the migration.
     *
     * This is what makes the expansion safe to run in production. Because every
     * target slug is already an accepted alias of the capability it belongs to,
     * materialising it grants nothing the role could not already do — the set is
     * neither widened nor narrowed, it is merely re-expressed.
     */
    public function the_effective_capability_set_is_unchanged_for_every_coarse_permission(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        foreach (self::COARSE_SLUGS as $index => $coarseSlug) {
            $role = $this->roleWith($business, "equivalence-{$index}", [$coarseSlug]);

            $before = $this->capabilitiesFor($this->slugsOf($role));

            $this->runMigration();

            $after = $this->capabilitiesFor($this->slugsOf($role->fresh()));

            $this->assertSame(
                $before,
                $after,
                "{$coarseSlug} must not change the role's effective capability set.",
            );

            $this->assertNotEmpty($before, "{$coarseSlug} should grant something before the migration.");
        }
    }

    /**
     * @test
     *
     * The expansion map is not a hand-written guess: each target must be a
     * capability the coarse permission *already* satisfied. The pre-image is
     * read from the frozen {@see self::LEGACY_CAPABILITY_SLUGS} table, so this
     * still fails if the migration ever adds a target the coarse grant did not
     * imply.
     */
    public function every_expansion_target_was_already_satisfied_by_the_coarse_permission(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        foreach (self::COARSE_SLUGS as $index => $coarseSlug) {
            $role = $this->roleWith($business, "preimage-{$index}", [$coarseSlug]);

            $this->runMigration();

            // Resolve each capability through the coarse slug *alone*.
            $coarseOnly = [];

            foreach (self::LEGACY_CAPABILITY_SLUGS as $capability => $accepted) {
                if (in_array($coarseSlug, $accepted, true)) {
                    $coarseOnly[] = $capability;
                }
            }

            sort($coarseOnly);

            $this->assertSame(
                $coarseOnly,
                $this->capabilitiesFor($this->slugsOf($role->fresh())),
                "{$coarseSlug} capability resolution changed after the migration.",
            );
        }
    }

    // ------------------------------------------------------------------
    // Additivity, idempotency and rollback safety
    // ------------------------------------------------------------------

    /** @test */
    public function running_the_migration_twice_creates_no_duplicates(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'idempotent', ['products.manage', 'sales.manage']);

        $this->runMigration();
        $afterFirst = DB::table('permission_role')->count();

        // Re-running against already-expanded data must be a no-op. Without the
        // existence filter this would violate the unique(permission_id, role_id)
        // index and abort.
        $this->runMigration();
        $this->runMigration();

        $this->assertSame(
            $afterFirst,
            DB::table('permission_role')->count(),
            'Repeated expansion must not add any rows.',
        );

        $duplicates = DB::table('permission_role')
            ->selectRaw('permission_id, role_id, COUNT(*) as total')
            ->groupBy('permission_id', 'role_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates, 'The unique(role_id, permission_id) property must hold.');
    }

    /** @test */
    public function coarse_permission_rows_and_catalogue_rows_are_never_deleted(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'preserved', self::COARSE_SLUGS);

        $coarseRowsBefore = $this->coarseRowCount();
        $catalogueBefore = DB::table('permissions')->count();

        $this->runMigration();

        $this->assertSame(
            $coarseRowsBefore,
            $this->coarseRowCount(),
            'Every coarse role_permission row must survive the migration.',
        );

        $this->assertSame(
            $catalogueBefore,
            DB::table('permissions')->count(),
            'The permission catalogue must be untouched: no rows added, none removed.',
        );

        foreach (self::COARSE_SLUGS as $coarseSlug) {
            $this->assertDatabaseHas('permissions', ['slug' => $coarseSlug]);
            $this->assertContains($coarseSlug, $this->slugsOf($role->fresh()), "{$coarseSlug} must survive.");
        }
    }

    /**
     * @test
     *
     * `down()` must not be able to destroy fine-grained grants it did not create.
     * Rolling back has to leave the data exactly as it found it, because
     * `permission_role` records no provenance for a row.
     */
    public function rolling_back_deletes_nothing(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'rollback', ['products.manage']);

        // A fine-grained grant that exists *independently* of this migration.
        $independent = Permission::query()->where('slug', 'categories.view')->value('id');
        $role->permissions()->syncWithoutDetaching([$independent]);

        $this->runMigration();

        $before = DB::table('permission_role')->count();

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();

        $this->assertSame(
            $before,
            DB::table('permission_role')->count(),
            'down() must not remove any fine-grained row, including independently granted ones.',
        );

        $this->assertContains('products.view', $this->slugsOf($role->fresh()));
        $this->assertContains('categories.view', $this->slugsOf($role->fresh()));
    }

    // ------------------------------------------------------------------
    // Tenant safety and edge cases
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * Permissions are global but roles are business-scoped. Business A's coarse
     * grant must expand business A's role only — never business B's role of the
     * same shape, and never a role that held no coarse permission at all.
     */
    public function a_coarse_grant_expands_only_its_own_role(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $this->seedCatalogueWithoutGrants();

        // `roles` is unique on (business_id, slug), and the seeder has already
        // created a `manager` role for every business, so these fixtures use
        // distinct slugs.
        $roleA = $this->roleWith($businessA, 'tenant-a-coarse', ['products.manage']);
        $roleB = $this->roleWith($businessB, 'tenant-b-empty', []);
        $roleC = $this->roleWith($businessB, 'tenant-b-fine', ['products.view']);

        $this->runMigration();

        $this->assertContains('products.view', $this->slugsOf($roleA->fresh()));

        $this->assertSame(
            ['products.view'],
            $this->slugsOf($roleC->fresh()),
            'A role holding only a fine-grained grant must be left exactly as it was.',
        );

        foreach (['products.view', 'products.create', 'products.update', 'products.delete'] as $slug) {
            $this->assertNotContains(
                $slug,
                $this->slugsOf($roleB->fresh()),
                "{$slug} leaked into a role that never held products.manage.",
            );
        }
    }

    /** @test */
    public function it_is_a_no_op_when_there_are_no_roles(): void
    {
        $this->seedCatalogueWithoutGrants();

        $before = DB::table('permission_role')->count();

        $this->runMigration();

        $this->assertSame(0, $before, 'The fixture must start with no role grants at all.');
        $this->assertSame($before, DB::table('permission_role')->count());
    }

    /** @test */
    public function it_is_a_no_op_when_no_coarse_permission_is_granted(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'fine-only', [
            'products.view',
            'sales.create',
            PermissionService::REPORTS_VIEW,
        ]);

        $before = DB::table('permission_role')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('permission_role')->count(), 'Nothing to expand.');

        $this->assertSame(
            ['products.view', 'reports.view', 'sales.create'],
            $this->sortedSlugsOf($role->fresh()),
        );
    }

    /** @test */
    public function it_tolerates_a_role_holding_coarse_and_fine_permissions_together(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogueWithoutGrants();

        // Already partly expanded by hand: `products.view` pre-exists and must
        // not be inserted twice, while the rest of `products.*` is completed.
        // `sales.view` is fine-grained only, so it must be left completely alone.
        $role = $this->roleWith($business, 'mixed', [
            'products.manage',
            'products.view',
            'sales.view',
        ]);

        $this->runMigration();

        $this->assertSame(
            [
                'products.create',
                'products.delete',
                'products.manage',
                'products.update',
                'products.view',
                'sales.view',
            ],
            $this->sortedSlugsOf($role->fresh()),
        );

        $this->assertSame(
            1,
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.slug', 'products.view')
                ->where('permission_role.role_id', $role->id)
                ->count(),
            'A pre-existing fine-grained grant must not be duplicated.',
        );
    }

    /** @test */
    public function it_does_nothing_when_the_permission_catalogue_is_empty(): void
    {
        // No seeder at all: the catalogue has no rows, so there is nothing to
        // resolve and nothing to expand.
        $before = DB::table('permission_role')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('permission_role')->count());
        $this->assertSame(0, DB::table('permissions')->count());
    }

    // ------------------------------------------------------------------
    // User overrides are untouched
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * Step 5 is a role-level data migration. `user_permissions` rows and the
     * DENY > ALLOW > role precedence must survive it untouched, including a DENY
     * that overrides a permission this migration newly materialises.
     */
    public function user_permission_overrides_are_left_untouched(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'overridden', ['products.manage']);

        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $denied = Permission::query()->where('slug', 'products.view')->value('id');
        $allowed = Permission::query()->where('slug', 'sales.payments.create')->value('id');

        UserPermission::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'permission_id' => $denied,
            'effect' => UserPermission::EFFECT_DENY,
        ]);

        UserPermission::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'permission_id' => $allowed,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);

        // Compared as scalar arrays: two `stdClass` rows holding equal values
        // are still different objects, so `assertSame` on the raw rows would
        // fail on identity rather than on content.
        $before = $this->overrideRows();

        $this->runMigration();

        $this->assertSame(
            $before,
            $this->overrideRows(),
            'The migration must not touch a single user override row.',
        );

        // The expansion happened...
        $this->assertContains('products.view', $this->slugsOf($role->fresh()));

        // ...and Step 3's precedence still decides the outcome.
        $fresh = $user->fresh();

        $this->assertFalse(
            $fresh->hasPermission(PermissionService::PRODUCTS_VIEW),
            'A user DENY on products.view must still outrank the new role grant.',
        );

        $this->assertTrue(
            $fresh->hasPermission(PermissionService::PRODUCTS_CREATE),
            'Unrelated capabilities are unaffected by the expansion.',
        );
    }

    /** @test */
    public function role_assignments_are_never_changed(): void
    {
        $business = Business::factory()->create();
        $this->seedCatalogue();

        $role = $this->roleWith($business, 'stable', ['products.manage']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $before = DB::table('role_user')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('role_user')->count(), 'role_user must be untouched.');
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** How many `permission_role` rows point at one of the 14 coarse slugs. */
    private function coarseRowCount(): int
    {
        $ids = Permission::query()->whereIn('slug', self::COARSE_SLUGS)->pluck('id');

        return DB::table('permission_role')->whereIn('permission_id', $ids)->count();
    }

    /**
     * Every user override row as plain scalars, ordered deterministically.
     *
     * @return array<int, array<string, mixed>>
     */
    private function overrideRows(): array
    {
        return DB::table('user_permissions')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /** @return array<int, string> */
    private function sortedSlugsOf(Role $role): array
    {
        $slugs = $this->slugsOf($role);
        sort($slugs);

        return $slugs;
    }
}
