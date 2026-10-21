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
 * Step 7 — removal of the retired coarse `<resource>.manage` catalogue.
 *
 * The migration is the first *destructive* step of the authorization cleanup:
 * everything before it was additive, so this suite is where the guarantees that
 * destruction is safe actually have to be proven.
 *
 * What it asserts, in four groups:
 *
 *  1. **Catalogue cleanup** — all 14 coarse rows, their role grants and their
 *     user overrides are gone, and the canonical rows, roles, users and
 *     `role_user` rows are untouched.
 *  2. **Authorization equivalence** — the effective capability set of every
 *     test user is identical before and after, resolved *before* through the
 *     frozen pre-Step-6 alias table and *after* through the live
 *     `PermissionService`. This is the assertion that matters; the rest are
 *     structural detail underneath it.
 *  3. **Override collision handling** — the conservative precedence
 *     `DENY > ALLOW`, the overlapping coarse aliases (`expenses.manage` /
 *     `expense_categories.manage`, `roles.manage` / `users.roles.update`) and
 *     tenant isolation.
 *  4. **Operational properties** — idempotency on re-run, safe behaviour on a
 *     fresh database, and a non-destructive `down()`.
 *
 * ## Why the historical model is frozen here
 *
 * `PermissionService::CAPABILITIES` no longer knows any coarse slug, so reading
 * the live service to decide "what did `products.manage` authorise?" would be a
 * tautology that passes vacuously. The pre-Step-6 alias table is therefore
 * restated in {@see self::LEGACY_CAPABILITY_SLUGS} exactly as the Step 5 suite
 * restates it, and the migration's own map is checked against that frozen
 * record rather than against the code it ships with.
 */
class CoarseManagePermissionRemovalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_01_300000_remove_coarse_manage_permissions.php';

    /** The 14 coarse permissions this step removes. */
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
     * The migration's map, coarse-first, restated for cross-checking.
     *
     * @var array<string, array<int, string>>
     */
    private const EXPECTED_TARGETS = [
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
            'expense_categories.view', 'expense_categories.create',
            'expense_categories.update', 'expense_categories.delete',
        ],
        'users.manage' => [
            'users.view', 'users.create', 'users.update', 'users.roles.update',
        ],
        'roles.manage' => [
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'permissions.view', 'users.roles.update',
        ],
        'settings.manage' => ['settings.view', 'settings.update'],
        'inventory.manage' => ['inventory.view', 'inventory.adjust'],
        'cash_registers.manage' => [
            'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
            'cash_registers.transactions.create',
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
     * The pre-Step-6 alias model, capability-first: capability => accepted slugs.
     *
     * A frozen copy of `PermissionService::CAPABILITIES` as it stood when the
     * coarse rows were still the seeded convention, cross-checked against the
     * Step 5 suite's identical table. Everything this file asserts about
     * "what a user could do before" is answered from here.
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
        // The coarse row *was* the adjust capability before Step 6.
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

        // Cross-domain: `expenses.manage` satisfied these too.
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
        // Likewise the cash-movement write.
        'cash_registers.manage' => ['cash_registers.manage', 'cash_registers.transactions.create'],

        'reports.view' => ['reports.view', 'reports.manage'],
    ];

    /**
     * Execute the migration's `up()` against the current test database.
     *
     * `require` (not `require_once`) is deliberate: each call re-evaluates the
     * file and yields a fresh anonymous migration instance, which is what lets
     * this suite run the real production code repeatedly to prove idempotency.
     */
    private function runMigration(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);

        $migration->up();
    }

    /**
     * Build the "before" state: the canonical catalogue plus the 14 coarse rows.
     *
     * The current seeder no longer creates the coarse rows, so this suite plants
     * them itself. That is not a workaround — it *is* the fixture the migration
     * exists for: an installation upgrading from the coarse catalogue.
     */
    private function seedLegacyCatalogue(): void
    {
        if (DB::table('businesses')->count() === 0) {
            Business::factory()->create();
        }

        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        foreach (self::COARSE_SLUGS as $slug) {
            Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucwords(str_replace('.', ' ', $slug))],
            );
        }
    }

    /**
     * Seed the catalogue but strip every role grant, so fixtures stay explicit.
     *
     * `RolesAndPermissionsSeeder` hands the whole catalogue to each business's
     * `admin` role, which would otherwise contribute grants these tests did not
     * create and could not account for.
     */
    private function seedLegacyCatalogueWithoutGrants(): void
    {
        $this->seedLegacyCatalogue();

        DB::table('permission_role')->delete();
    }

    /**
     * Create a role holding exactly the given (literal) slugs.
     *
     * @param  array<int, string>  $slugs
     */
    private function roleWith(Business $business, string $slug, array $slugs): Role
    {
        $role = Role::create([
            'business_id' => $business->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Coarse removal fixture.',
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

    /** @return array<int, string> */
    private function sortedSlugsOf(Role $role): array
    {
        $slugs = $this->slugsOf($role);
        sort($slugs);

        return $slugs;
    }

    /**
     * Write a user override against a literal slug.
     *
     * No capability-name resolution here either: `inventory.manage` and
     * `cash_registers.manage` are both retired coarse slugs *and* live
     * capability names, so a fixture that resolved names would silently create a
     * different row than the one it asked for.
     */
    private function override(User $user, string $slug, string $effect, ?int $businessId = null): UserPermission
    {
        return UserPermission::create([
            'business_id' => $businessId ?? (int) $user->business_id,
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('slug', $slug)->value('id'),
            'effect' => $effect,
        ]);
    }

    /** How many `permission_role` rows point at one of the 14 coarse slugs. */
    private function coarseRoleGrantCount(): int
    {
        return DB::table('permission_role')
            ->whereIn('permission_id', $this->idsForSlugs(self::COARSE_SLUGS))
            ->count();
    }

    /** How many `user_permissions` rows point at one of the 14 coarse slugs. */
    private function coarseOverrideCount(): int
    {
        return DB::table('user_permissions')
            ->whereIn('permission_id', $this->idsForSlugs(self::COARSE_SLUGS))
            ->count();
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    private function idsForSlugs(array $slugs): array
    {
        return Permission::query()->whereIn('slug', $slugs)->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The tenant's override rows a user holds, as `slug => effect`, sorted.
     *
     * @return array<string, string>
     */
    private function overridesOf(User $user): array
    {
        $rows = DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $user->id)
            ->where('user_permissions.business_id', $user->business_id)
            ->orderBy('permissions.slug')
            ->get(['permissions.slug', 'user_permissions.effect']);

        $map = [];

        foreach ($rows as $row) {
            $map[$row->slug] = $row->effect;
        }

        return $map;
    }

    /**
     * The capability set a user had *before* Step 6/7, from the frozen model.
     *
     * Resolved through the legacy alias table rather than the live service, which
     * no longer recognises a coarse slug. `Effective = (role permissions ∪ user
     * ALLOW) − user DENY` is the same precedence the live resolver applies, so
     * the only thing that differs between before and after is the
     * slug→capability mapping — which is exactly what is under test.
     *
     * @return array<int, string>
     */
    private function legacyCapabilities(User $user): array
    {
        $denied = [];
        $allowed = [];

        foreach ($this->overridesOf($user) as $slug => $effect) {
            if ($effect === UserPermission::EFFECT_DENY) {
                $denied[] = $slug;
            } else {
                $allowed[] = $slug;
            }
        }

        $effective = array_values(array_diff(
            array_unique(array_merge($user->fresh()->permissionSlugs(), $allowed)),
            $denied,
        ));

        $granted = [];

        foreach (self::LEGACY_CAPABILITY_SLUGS as $capability => $accepted) {
            // The live resolver refuses a capability when *any* of its accepted
            // slugs is denied — an alias DENY takes the whole capability down —
            // and grants it when *any* accepted slug is effective. Both halves
            // matter here; the DENY half is what makes an overlapping coarse
            // DENY (`expenses.manage`) still deny `expense_categories.*`.
            $isDenied = array_intersect($accepted, $denied) !== [];
            $isEffective = array_intersect($accepted, $effective) !== [];

            if ($isEffective && ! $isDenied) {
                $granted[] = $capability;
            }
        }

        sort($granted);

        return $granted;
    }

    /**
     * The capability set a user has *now*, through the live resolver.
     *
     * @return array<int, string>
     */
    private function currentCapabilities(User $user): array
    {
        $capabilities = app(PermissionService::class)->resolvedCapabilities($user->fresh());

        sort($capabilities);

        return $capabilities;
    }

    // ------------------------------------------------------------------
    // The migration map is provably safe
    // ------------------------------------------------------------------

    /** @test */
    public function the_migration_map_covers_exactly_the_fourteen_retired_slugs(): void
    {
        $this->assertSame(
            self::COARSE_SLUGS,
            array_keys(self::EXPECTED_TARGETS),
            'The expected map must list the same 14 coarse slugs this step retires.',
        );

        // Every target is a slug the current catalogue actually authorises against.
        $canonical = PermissionService::catalogueSlugs();

        foreach (self::EXPECTED_TARGETS as $coarse => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $canonical, "{$coarse} maps onto an unknown slug {$target}.");
                $this->assertNotContains($target, self::COARSE_SLUGS, "{$coarse} must not map onto another coarse slug.");
            }
        }
    }

    /**
     * @test
     *
     * Every target must be a capability the coarse slug already satisfied through
     * the historical alias table. That is what makes the removal a migration rather
     * than a revocation: nothing here grants a capability the coarse row did not
     * already imply.
     */
    public function every_mapped_target_was_already_implied_by_the_coarse_permission(): void
    {
        foreach (self::EXPECTED_TARGETS as $coarse => $targets) {
            $implied = [];

            foreach (self::LEGACY_CAPABILITY_SLUGS as $capability => $accepted) {
                if (in_array($coarse, $accepted, true)) {
                    $implied[] = PermissionService::slugFor($capability);
                }
            }

            $expected = array_values(array_unique($implied));
            $actual = array_values(array_unique($targets));

            sort($expected);
            sort($actual);

            $this->assertSame(
                $expected,
                $actual,
                "{$coarse} maps onto a different set than the historical alias implied.",
            );
        }
    }

    /** @test */
    public function the_cross_domain_mappings_are_preserved(): void
    {
        $expenseCategorySlugs = [
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',
        ];

        // `expenses.manage` reached into the expense-category CRUD set...
        $this->assertSame(
            $expenseCategorySlugs,
            array_values(array_intersect(self::EXPECTED_TARGETS['expenses.manage'], $expenseCategorySlugs)),
        );

        // ...and `roles.manage` reached into two other domains entirely.
        $this->assertContains('permissions.view', self::EXPECTED_TARGETS['roles.manage']);
        $this->assertContains('users.roles.update', self::EXPECTED_TARGETS['roles.manage']);
    }

    // ------------------------------------------------------------------
    // Catalogue and role-grant cleanup
    // ------------------------------------------------------------------

    /** @test */
    public function all_fourteen_coarse_catalogue_rows_are_removed(): void
    {
        $this->seedLegacyCatalogue();

        $this->runMigration();

        foreach (self::COARSE_SLUGS as $slug) {
            $this->assertDatabaseMissing('permissions', ['slug' => $slug]);
        }

        // Nothing canonical was collateral damage.
        foreach (PermissionService::catalogueSlugs() as $slug) {
            $this->assertSame(1, Permission::query()->where('slug', $slug)->count(), "{$slug} must survive.");
        }

        $this->assertSame(
            count(PermissionService::catalogueSlugs()),
            DB::table('permissions')->count(),
            'The catalogue must be exactly the canonical set afterwards.',
        );
    }

    /** @test */
    public function all_coarse_role_grants_are_removed_and_the_canonical_ones_remain(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'everything', self::COARSE_SLUGS);
        $this->assertSame(14, $this->coarseRoleGrantCount());

        $this->runMigration();

        $this->assertSame(0, $this->coarseRoleGrantCount(), 'No coarse role grant may survive.');

        // Every canonical target the coarse rows stood for is now granted directly.
        foreach (self::EXPECTED_TARGETS as $coarse => $targets) {
            foreach ($targets as $target) {
                $this->assertContains(
                    $target,
                    $this->slugsOf($role->fresh()),
                    "{$target} (from {$coarse}) must be granted after the cleanup.",
                );
            }
        }
    }

    /** @test */
    public function effective_role_capabilities_are_identical_before_and_after(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'broad', [
            'products.manage', 'sales.manage', 'expenses.manage', 'roles.manage',
            'users.manage', 'inventory.manage', 'cash_registers.manage', 'reports.view',
        ]);

        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $before = $this->legacyCapabilities($user);
        $this->assertNotEmpty($before);

        $this->runMigration();

        $this->assertSame($before, $this->currentCapabilities($user));
    }

    /**
     * @test
     *
     * The two non-obvious canonical writes: `inventory.manage` and
     * `cash_registers.manage` are *capability names*, and the coarse rows used to
     * be their slugs. A role holding only those two rows before must still be able
     * to adjust stock and move cash afterwards.
     */
    public function the_inventory_and_cash_movement_writes_survive(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'stock-and-till', ['inventory.manage', 'cash_registers.manage']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $before = $this->legacyCapabilities($user);
        $this->assertContains(PermissionService::INVENTORY_MANAGE, $before);
        $this->assertContains(PermissionService::CASH_REGISTERS_MANAGE, $before);

        $this->runMigration();

        $slugs = $this->slugsOf($role->fresh());

        $this->assertContains(PermissionService::INVENTORY_ADJUST, $slugs);
        $this->assertContains(PermissionService::CASH_REGISTERS_TRANSACTIONS_CREATE, $slugs);
        $this->assertContains(PermissionService::INVENTORY_VIEW, $slugs);
        $this->assertContains(PermissionService::CASH_REGISTERS_VIEW, $slugs);

        $this->assertSame($before, $this->currentCapabilities($user));
    }

    /**
     * @test
     *
     * Step 5 normally does this work. This covers an installation that skipped it
     * or applied it partially: the migration must repair the role grants itself
     * rather than delete a coarse row that is the only thing authorising something.
     */
    public function it_repairs_a_role_whose_step_five_expansion_never_happened(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'skipped-step-5', [
            'expenses.manage',
            'roles.manage',
        ]);

        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $slugs = $this->slugsOf($role->fresh());

        foreach (self::EXPECTED_TARGETS['expenses.manage'] as $target) {
            $this->assertContains($target, $slugs);
        }

        foreach (self::EXPECTED_TARGETS['roles.manage'] as $target) {
            $this->assertContains($target, $slugs);
        }

        $this->assertSame($before, $this->currentCapabilities($user));
    }

    /** @test */
    public function it_does_not_duplicate_a_grant_step_five_already_materialised(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        // Exactly what Step 5 leaves behind: the coarse row plus its expansion.
        $role = $this->roleWith($business, 'already-expanded', array_merge(
            ['products.manage'],
            self::EXPECTED_TARGETS['products.manage'],
        ));

        $before = DB::table('permission_role')->count();
        $this->assertSame(5, $before, 'One coarse row plus four expanded rows.');

        $this->runMigration();

        $this->assertSame(
            $before - 1,
            DB::table('permission_role')->count(),
            'A fully expanded role must gain nothing and lose exactly its coarse row.',
        );

        $this->assertSame(
            1,
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.role_id', $role->id)
                ->where('permissions.slug', 'products.view')
                ->count(),
            'products.view must appear exactly once.',
        );
    }

    /** @test */
    public function role_grants_never_leak_across_businesses(): void
    {
        $this->seedLegacyCatalogue();

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $roleA = $this->roleWith($businessA, 'tenant-a', ['products.manage']);
        $roleB = $this->roleWith($businessB, 'tenant-b', ['sales.view']);

        $this->runMigration();

        $this->assertContains('products.view', $this->slugsOf($roleA->fresh()));
        $this->assertSame(
            ['sales.view'],
            $this->sortedSlugsOf($roleB->fresh()),
            "Business B must gain nothing from business A's coarse grant.",
        );
    }

    /** @test */
    public function roles_users_businesses_and_role_user_are_untouched(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'stable', self::COARSE_SLUGS);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $roles = DB::table('roles')->count();
        $users = DB::table('users')->count();
        $assignments = DB::table('role_user')->count();

        $this->runMigration();

        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($assignments, DB::table('role_user')->count());
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------------
    // Idempotency, rollback and fresh databases
    // ------------------------------------------------------------------

    /** @test */
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'repeatable', self::COARSE_SLUGS);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);
        $this->override($user, 'products.manage', UserPermission::EFFECT_DENY);

        $this->runMigration();

        $permissions = DB::table('permissions')->count();
        $roleGrants = DB::table('permission_role')->count();
        $overrides = DB::table('user_permissions')->count();
        $capabilities = $this->currentCapabilities($user);

        // Both calls must succeed even though the coarse rows are long gone.
        $this->runMigration();
        $this->runMigration();

        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($roleGrants, DB::table('permission_role')->count());
        $this->assertSame($overrides, DB::table('user_permissions')->count());
        $this->assertSame($capabilities, $this->currentCapabilities($user));
        $this->assertSame(0, $this->coarseRoleGrantCount());
        $this->assertSame(0, $this->coarseOverrideCount());

        $this->assertCount(0, $this->duplicatePairs('permission_role', ['permission_id', 'role_id']));
        $this->assertCount(0, $this->duplicatePairs('user_permissions', ['business_id', 'user_id', 'permission_id']));
    }

    /** @test */
    public function it_is_a_no_op_on_a_fresh_catalogue_with_no_coarse_rows(): void
    {
        Business::factory()->create();
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $permissions = DB::table('permissions')->count();
        $roleGrants = DB::table('permission_role')->count();

        $this->runMigration();

        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($roleGrants, DB::table('permission_role')->count());
    }

    /**
     * @test
     *
     * A coarse row whose canonical replacement is missing must be left alone rather
     * than deleted: dropping it would destroy authorization instead of migrating it.
     */
    public function it_leaves_a_coarse_row_alone_when_its_replacement_is_missing(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogue();

        // An installation not yet seeded with `inventory.adjust`.
        Permission::query()->where('slug', 'inventory.adjust')->delete();

        $role = $this->roleWith($business, 'incomplete-catalogue', ['inventory.manage']);

        $this->runMigration();

        $this->assertDatabaseHas('permissions', ['slug' => 'inventory.manage']);
        $this->assertContains('inventory.manage', $this->slugsOf($role->fresh()));
        $this->assertNotContains('inventory.adjust', $this->slugsOf($role->fresh()));

        // Every other coarse row was still retired.
        $this->assertDatabaseMissing('permissions', ['slug' => 'products.manage']);
    }

    /**
     * @test
     *
     * `down()` must not be able to reconstruct the coarse rows. Doing so would
     * resurrect a taxonomy nothing authorises against and, worse, tempt a future
     * maintainer into deleting canonical rows that may have been granted
     * independently.
     */
    public function rolling_back_deletes_nothing(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'rollback', ['products.manage']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        // A canonical grant that exists entirely independently of any coarse row.
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'categories.view')->value('id'),
        ]);

        $this->override($user, 'products.manage', UserPermission::EFFECT_DENY);

        $this->runMigration();

        $permissions = DB::table('permissions')->count();
        $roleGrants = DB::table('permission_role')->count();
        $overrides = DB::table('user_permissions')->count();

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();

        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($roleGrants, DB::table('permission_role')->count());
        $this->assertSame($overrides, DB::table('user_permissions')->count());

        foreach (self::COARSE_SLUGS as $slug) {
            $this->assertSame(0, Permission::query()->where('slug', $slug)->count(), "{$slug} must stay retired.");
        }

        $this->assertContains('products.view', $this->slugsOf($role->fresh()));
        $this->assertContains('categories.view', $this->slugsOf($role->fresh()));
        $this->assertSame(
            $this->expectedOverrides(self::EXPECTED_TARGETS['products.manage'], 'deny'),
            $this->overridesOf($user),
            'The migrated DENY overrides must survive a rollback untouched.',
        );
    }

    // ------------------------------------------------------------------
    // User override migration
    // ------------------------------------------------------------------

    /** @test */
    public function a_coarse_allow_override_expands_to_an_allow_on_every_target(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'products.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $expected = $this->expectedOverrides(self::EXPECTED_TARGETS['products.manage'], UserPermission::EFFECT_ALLOW);

        $this->assertSame($expected, $this->overridesOf($user));
        $this->assertSame(0, $this->coarseOverrideCount());
        $this->assertSame($before, $this->currentCapabilities($user));

        // Nothing outside the mapped set was invented.
        $this->assertCount(count(self::EXPECTED_TARGETS['products.manage']), $this->overridesOf($user));
    }

    /** @test */
    public function a_coarse_deny_override_expands_to_a_deny_on_every_target(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'products.manage', UserPermission::EFFECT_DENY);

        $before = $this->legacyCapabilities($user);
        $this->assertSame([], $before, 'A lone DENY grants nothing to begin with.');

        $this->runMigration();

        $expected = $this->expectedOverrides(self::EXPECTED_TARGETS['products.manage'], UserPermission::EFFECT_DENY);

        $this->assertSame($expected, $this->overridesOf($user));
        $this->assertSame([], $this->currentCapabilities($user));
    }

    /** @test */
    public function an_existing_fine_allow_is_downgraded_when_a_coarse_deny_arrives(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'products.view', UserPermission::EFFECT_ALLOW);
        $this->override($user, 'products.manage', UserPermission::EFFECT_DENY);

        $this->runMigration();

        $overrides = $this->overridesOf($user);

        $this->assertSame('deny', $overrides['products.view'], 'A migrated DENY must outrank an existing ALLOW.');
        $this->assertSame('deny', $overrides['products.create']);
        $this->assertSame('deny', $overrides['products.update']);
        $this->assertSame('deny', $overrides['products.delete']);

        $this->assertCount(4, $overrides, 'The collision must update in place, never duplicate.');
        $this->assertSame([], $this->currentCapabilities($user));
    }

    /** @test */
    public function an_existing_fine_deny_is_never_overridden_by_a_coarse_allow(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'products.view', UserPermission::EFFECT_DENY);
        $this->override($user, 'products.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $overrides = $this->overridesOf($user);

        $this->assertSame('deny', $overrides['products.view'], 'An existing DENY is authoritative.');
        $this->assertSame('allow', $overrides['products.create']);
        $this->assertSame('allow', $overrides['products.update']);
        $this->assertSame('allow', $overrides['products.delete']);

        $this->assertCount(4, $overrides);
        $this->assertSame($before, $this->currentCapabilities($user));
    }

    /**
     * @test
     *
     * `expenses.manage` and `expense_categories.manage` map onto an overlapping
     * set. A DENY on the former and an ALLOW on the latter must resolve by
     * precedence, not by which row happened to be written or migrated first.
     */
    public function overlapping_expense_aliases_resolve_by_precedence(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'expenses.manage', UserPermission::EFFECT_DENY);
        $this->override($user, 'expense_categories.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $overrides = $this->overridesOf($user);

        foreach (['expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete'] as $slug) {
            $this->assertSame('deny', $overrides[$slug], "{$slug} comes only from expenses.manage (DENY).");
        }

        // The contested set: both coarse rows reach it, and DENY must win.
        foreach (self::EXPECTED_TARGETS['expense_categories.manage'] as $slug) {
            $this->assertSame('deny', $overrides[$slug], "{$slug} is contested and must resolve to DENY.");
        }

        $this->assertCount(8, $overrides, 'Exactly one row per canonical permission, never two.');
        $this->assertSame([], $this->currentCapabilities($user));
        $this->assertSame(
            $before,
            $this->currentCapabilities($user),
            'A DENY on expenses.manage already denied expense_categories.* before the migration too.',
        );
    }

    /**
     * @test
     *
     * The cross-domain pair: an existing DENY on `users.roles.update` must survive a
     * coarse ALLOW on `roles.manage`, while the rest of the role CRUD set and the
     * cross-domain `permissions.view` are granted normally.
     */
    public function overlapping_roles_aliases_resolve_by_precedence(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'users.roles.update', UserPermission::EFFECT_DENY);
        $this->override($user, 'roles.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $overrides = $this->overridesOf($user);

        $this->assertSame('deny', $overrides['users.roles.update'], 'The pre-existing DENY must win.');
        $this->assertSame('allow', $overrides['permissions.view']);
        $this->assertSame('allow', $overrides['roles.view']);
        $this->assertSame('allow', $overrides['roles.create']);
        $this->assertSame('allow', $overrides['roles.update']);
        $this->assertSame('allow', $overrides['roles.delete']);

        $this->assertCount(6, $overrides);
        $this->assertSame($before, $this->currentCapabilities($user));
        $this->assertNotContains(PermissionService::USERS_ROLES_UPDATE, $this->currentCapabilities($user));
    }

    /** @test */
    public function the_inventory_override_migrates_to_the_canonical_write_slug(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'inventory.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $this->assertSame(
            ['inventory.adjust' => 'allow', 'inventory.view' => 'allow'],
            $this->overridesOf($user),
            'The coarse inventory row must become inventory.view plus inventory.adjust.',
        );

        $this->assertSame($before, $this->currentCapabilities($user));
        $this->assertContains(PermissionService::INVENTORY_MANAGE, $this->currentCapabilities($user));
    }

    /** @test */
    public function the_cash_register_override_migrates_to_all_four_canonical_slugs(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $user = User::factory()->create(['business_id' => $business->id]);
        $this->override($user, 'cash_registers.manage', UserPermission::EFFECT_ALLOW);

        $before = $this->legacyCapabilities($user);

        $this->runMigration();

        $this->assertSame(
            [
                'cash_registers.create' => 'allow',
                'cash_registers.transactions.create' => 'allow',
                'cash_registers.update' => 'allow',
                'cash_registers.view' => 'allow',
            ],
            $this->overridesOf($user),
        );

        $this->assertSame($before, $this->currentCapabilities($user));
        $this->assertContains(PermissionService::CASH_REGISTERS_MANAGE, $this->currentCapabilities($user));
    }

    /**
     * @test
     *
     * `business_id` is tenant-owned data even though `permissions` is global. A
     * migration that recomputed it from `users.business_id`, or copied a row between
     * tenants, would silently change who an override applies to.
     */
    public function overrides_never_cross_a_business_boundary(): void
    {
        $this->seedLegacyCatalogue();

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $userA = User::factory()->create(['business_id' => $businessA->id]);
        $userB = User::factory()->create(['business_id' => $businessB->id]);

        $this->override($userA, 'products.manage', UserPermission::EFFECT_ALLOW);
        $this->override($userB, 'sales.manage', UserPermission::EFFECT_DENY);

        $this->runMigration();

        $rowsA = $this->overridesOf($userA);
        $rowsB = $this->overridesOf($userB);

        $this->assertSame(
            $this->expectedOverrides(self::EXPECTED_TARGETS['products.manage'], 'allow'),
            $rowsA,
        );

        $this->assertSame(
            $this->expectedOverrides(self::EXPECTED_TARGETS['sales.manage'], 'deny'),
            $rowsB,
        );

        // Every migrated row kept the business it was written in.
        $this->assertSame(0, DB::table('user_permissions')
            ->where('permission_id', $this->idsForSlugs(self::EXPECTED_TARGETS['products.manage']))
            ->where('business_id', $businessB->id)
            ->count());

        $this->assertSame(0, DB::table('user_permissions')
            ->where('permission_id', $this->idsForSlugs(self::EXPECTED_TARGETS['sales.manage']))
            ->where('business_id', $businessA->id)
            ->count());

        // And neither user holds anything outside their own tenant's set.
        $this->assertCount(4, DB::table('user_permissions')->where('user_id', $userA->id)->get());
        $this->assertCount(5, DB::table('user_permissions')->where('user_id', $userB->id)->get());
    }

    /**
     * @test
     *
     * A row written against the wrong tenant is meaningless to authorization
     * (`User::tenantPermissionOverrides()` ignores it), and the migration must not
     * "repair" it by rewriting its `business_id` — that would grant authority the
     * application deliberately refuses to honour.
     */
    public function a_misattributed_cross_tenant_row_is_migrated_with_its_business_intact(): void
    {
        $this->seedLegacyCatalogue();

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $userA = User::factory()->create(['business_id' => $businessA->id]);

        $this->override($userA, 'products.manage', UserPermission::EFFECT_ALLOW, (int) $businessB->id);

        $this->runMigration();

        $foreign = DB::table('user_permissions')
            ->where('user_id', $userA->id)
            ->where('business_id', $businessB->id)
            ->count();

        $this->assertSame(4, $foreign, 'All four migrated rows must stay inside business B.');
        $this->assertSame(
            0,
            DB::table('user_permissions')->where('user_id', $userA->id)->where('business_id', $businessA->id)->count(),
            'Nothing may be re-homed into the user\'s own business.',
        );

        // It still authorises nothing, exactly as before.
        $this->assertSame([], $this->currentCapabilities($userA));
    }

    // ------------------------------------------------------------------
    // Authorization equivalence on a realistic old-state dataset
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * The end-to-end guarantee. A full old-state installation is built — two
     * businesses, several roles, a spread of users covering role-only access, coarse
     * ALLOWs, coarse DENYs, independent fine-grained overrides and deliberate
     * collisions — and every user's effective capability set is captured before and
     * compared after.
     *
     * "Before" is resolved through the frozen pre-Step-6 alias table; "after"
     * through the live `PermissionService`. Any difference is a regression, not an
     * expectation to be adjusted.
     */
    public function every_users_effective_capabilities_are_unchanged_by_the_cleanup(): void
    {
        $this->seedLegacyCatalogueWithoutGrants();

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $broad = $this->roleWith($businessA, 'broad', [
            'products.manage', 'sales.manage', 'inventory.manage', 'cash_registers.manage',
        ]);
        $finance = $this->roleWith($businessA, 'finance', [
            'expenses.manage', 'purchases.manage', 'reports.view',
        ]);
        $adminA = $this->roleWith($businessA, 'admin-role', self::COARSE_SLUGS);
        $tenantB = $this->roleWith($businessB, 'tenant-b', ['products.manage', 'categories.manage']);

        // 1. Role-only access.
        $roleOnly = User::factory()->create(['business_id' => $businessA->id]);
        $roleOnly->roles()->attach($broad);

        // 2. A coarse DENY against a broad role.
        $denied = User::factory()->create(['business_id' => $businessA->id]);
        $denied->roles()->attach($broad);
        $this->override($denied, 'products.manage', UserPermission::EFFECT_DENY);

        // 3. A coarse ALLOW on top of a narrow role.
        $allowed = User::factory()->create(['business_id' => $businessA->id]);
        $allowed->roles()->attach($finance);
        $this->override($allowed, 'sales.manage', UserPermission::EFFECT_ALLOW);

        // 4. Overlapping coarse aliases on one user.
        $overlap = User::factory()->create(['business_id' => $businessA->id]);
        $overlap->roles()->attach($finance);
        $this->override($overlap, 'expenses.manage', UserPermission::EFFECT_DENY);
        $this->override($overlap, 'expense_categories.manage', UserPermission::EFFECT_ALLOW);

        // 5. Cross-domain collision against an independent fine-grained DENY.
        $crossDomain = User::factory()->create(['business_id' => $businessA->id]);
        $crossDomain->roles()->attach($broad);
        $this->override($crossDomain, 'users.roles.update', UserPermission::EFFECT_DENY);
        $this->override($crossDomain, 'roles.manage', UserPermission::EFFECT_ALLOW);

        // 6. Fine ALLOW that a coarse DENY must beat.
        $fineAllow = User::factory()->create(['business_id' => $businessA->id]);
        $fineAllow->roles()->attach($broad);
        $this->override($fineAllow, 'products.view', UserPermission::EFFECT_ALLOW);
        $this->override($fineAllow, 'products.manage', UserPermission::EFFECT_DENY);

        // 7. Fine DENY that a coarse ALLOW must not lift.
        $fineDeny = User::factory()->create(['business_id' => $businessA->id]);
        $fineDeny->roles()->attach($broad);
        $this->override($fineDeny, 'products.view', UserPermission::EFFECT_DENY);
        $this->override($fineDeny, 'products.manage', UserPermission::EFFECT_ALLOW);

        // 8. Full-catalogue role, no overrides.
        $superuser = User::factory()->create(['business_id' => $businessA->id]);
        $superuser->roles()->attach($adminA);

        // 9. The second tenant, which must be completely unaffected.
        $otherTenant = User::factory()->create(['business_id' => $businessB->id]);
        $otherTenant->roles()->attach($tenantB);

        // 10. A user with nothing at all.
        $noAccess = User::factory()->create(['business_id' => $businessA->id]);

        $users = [
            'role only' => $roleOnly,
            'coarse DENY' => $denied,
            'coarse ALLOW' => $allowed,
            'overlapping aliases' => $overlap,
            'cross-domain collision' => $crossDomain,
            'fine ALLOW + coarse DENY' => $fineAllow,
            'fine DENY + coarse ALLOW' => $fineDeny,
            'every coarse permission' => $superuser,
            'other tenant' => $otherTenant,
            'no access' => $noAccess,
        ];

        $before = [];

        foreach ($users as $label => $user) {
            $before[$label] = $this->legacyCapabilities($user);
        }

        // The fixture must be meaningful: each label has to start from a distinct
        // place, otherwise the comparison below would pass vacuously.
        $this->assertNotSame([], $before['role only']);
        $this->assertSame([], $before['no access']);
        $this->assertContains(PermissionService::CASH_REGISTERS_MANAGE, $before['role only']);

        // A coarse DENY against a broad role removes exactly the product verbs.
        $this->assertNotEmpty(array_intersect($before['role only'], $this->productCapabilities()));
        $this->assertEmpty(array_intersect($before['coarse DENY'], $this->productCapabilities()));
        $this->assertNotEmpty(array_diff($before['role only'], $before['coarse DENY']));

        // A coarse ALLOW on a finance role adds the sales verbs.
        $this->assertContains(PermissionService::SALES_CREATE, $before['coarse ALLOW']);
        $this->assertContains(PermissionService::SALES_PAYMENTS_CREATE, $before['coarse ALLOW']);

        // The contested expense-category set resolves to denied either way.
        $this->assertNotContains(PermissionService::EXPENSE_CATEGORIES_VIEW, $before['overlapping aliases']);

        // The cross-domain collision keeps the pre-existing DENY.
        $this->assertNotContains(PermissionService::USERS_ROLES_UPDATE, $before['cross-domain collision']);
        $this->assertContains(PermissionService::PERMISSIONS_VIEW, $before['cross-domain collision']);

        // A migrated DENY beats a fine ALLOW, so every product verb is gone.
        $this->assertEmpty(array_intersect($before['fine ALLOW + coarse DENY'], $this->productCapabilities()));

        // ...while a migrated ALLOW does not lift a fine DENY.
        $this->assertNotContains(PermissionService::PRODUCTS_VIEW, $before['fine DENY + coarse ALLOW']);
        $this->assertContains(PermissionService::PRODUCTS_CREATE, $before['fine DENY + coarse ALLOW']);

        // A role holding all 14 coarse rows held every capability *except*
        // `reports.view` — no coarse permission implied it, which is exactly why
        // `reports.manage` was a dead alias. The set is therefore identical to the
        // full catalogue minus that one, and not merely "nearly everything".
        //
        // The two cash-movement corrections are excluded for the same reason:
        // they were introduced *after* the coarse convention was retired, so no
        // `<resource>.manage` row ever implied them. A pre-expansion install
        // genuinely could not hold them, and the migration must not invent the
        // grants retroactively — that is the seeder's additive job.
        //
        // `inventory.batches.view` is excluded for that same reason: STEP 4
        // introduced it as a brand-new least-privilege read, long after the
        // coarse rows had stopped implying anything. No `<resource>.manage` row
        // ever stood for it, so a pre-expansion installation could not hold it
        // either, and the "before" set must not pretend otherwise.
        $expected = array_values(array_diff(
            PermissionService::ALL_CAPABILITIES,
            [
                PermissionService::REPORTS_VIEW,
                PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE,
                PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE,
                PermissionService::INVENTORY_BATCHES_VIEW,
            ],
        ));
        sort($expected);

        $this->assertSame($expected, $before['every coarse permission']);

        $this->runMigration();

        foreach ($users as $label => $user) {
            $this->assertSame(
                $before[$label],
                $this->currentCapabilities($user),
                "Effective capabilities changed for the '{$label}' user.",
            );
        }
    }

    // ------------------------------------------------------------------
    // Fresh database and upgrade-path verification
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * A fresh install never has coarse rows, so the cleanup must be inert and the
     * seeded role matrix must still be exactly what the authorization model says.
     */
    public function a_fresh_database_needs_no_cleanup_and_seeds_the_expected_matrix(): void
    {
        $business = Business::factory()->create();
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->assertSame(count(PermissionService::catalogueSlugs()), DB::table('permissions')->count());

        foreach (DB::table('permissions')->pluck('slug') as $slug) {
            $this->assertNotSame('reports.manage', $slug);
            $this->assertStringNotContainsString('.manage', $slug, "{$slug} looks like a retired coarse slug.");
        }

        $before = DB::table('permissions')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('permissions')->count());

        // The seeded matrix, measured as effective capabilities. STEP 4 added
        // `inventory.batches.view` to the two till-facing roles — the manager
        // and the salesman — so both are one higher than before; the accountant
        // works no till and creates no sale, so it is unchanged.
        $expected = ['admin' => count(PermissionService::catalogueSlugs()), 'manager' => 45, 'salesman' => 24, 'accountant' => 18];

        foreach ($expected as $slug => $count) {
            $role = Role::query()->where('business_id', $business->id)->where('slug', $slug)->firstOrFail();

            $this->assertCount($count, $this->slugsOf($role), "The {$slug} role must hold {$count} permissions.");
            $this->assertCount(0, $this->duplicatePairs('permission_role', ['permission_id', 'role_id']));
        }
    }

    /**
     * @test
     *
     * The documented upgrade path, end to end: an old catalogue with old grants and
     * old overrides, through Step 5's expansion and then this cleanup, ends in the
     * canonical state with every user's capabilities untouched.
     */
    public function the_old_to_step_five_to_step_seven_upgrade_path_preserves_everything(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        // An old installation: roles granted coarse rows, nothing expanded yet.
        // The slugs are prefixed because the seeder already created the real
        // `manager` and `accountant` roles for this business.
        $manager = $this->roleWith($business, 'old-manager', [
            'products.manage', 'sales.manage', 'purchases.manage', 'expenses.manage',
            'inventory.manage', 'cash_registers.manage', 'reports.view',
        ]);
        $accountant = $this->roleWith($business, 'old-accountant', [
            'purchases.manage', 'suppliers.manage', 'expenses.manage',
            'expense_categories.manage', 'sales.payments.create', 'reports.view',
        ]);

        $staff = User::factory()->create(['business_id' => $business->id]);
        $staff->roles()->attach($manager);
        $this->override($staff, 'products.manage', UserPermission::EFFECT_DENY);

        $bookkeeper = User::factory()->create(['business_id' => $business->id]);
        $bookkeeper->roles()->attach($accountant);

        $before = [
            'staff' => $this->legacyCapabilities($staff),
            'bookkeeper' => $this->legacyCapabilities($bookkeeper),
        ];

        $permissionsBefore = DB::table('permissions')->count();
        $overridesBefore = DB::table('user_permissions')->count();
        $this->assertSame(1, $overridesBefore, 'The fixture starts with exactly one coarse override.');

        $this->assertSame(count(PermissionService::catalogueSlugs()) + 14, $permissionsBefore, 'The canonical catalogue plus the 14 coarse rows.');

        // Step 5 — the historical expansion migration, then this step.
        $this->runStepFive();
        $this->runMigration();

        $this->assertSame(
            count(PermissionService::catalogueSlugs()),
            DB::table('permissions')->count(),
            'The two migrations together must leave exactly the canonical catalogue.',
        );

        $this->assertSame(0, $this->coarseRoleGrantCount(), 'No coarse role grant may remain.');
        $this->assertSame(0, $this->coarseOverrideCount(), 'No coarse override may remain.');
        $this->assertSame(4, $this->overrideCount(), 'Exactly the four canonical DENY rows remain.');

        // Canonical role grants survived.
        $this->assertContains('products.view', $this->slugsOf($manager->fresh()));
        $this->assertContains('inventory.adjust', $this->slugsOf($manager->fresh()));
        $this->assertContains('cash_registers.transactions.create', $this->slugsOf($manager->fresh()));
        $this->assertContains('sales.payments.create', $this->slugsOf($accountant->fresh()));

        // Canonical user overrides survived, coarse ones gone.
        $this->assertSame(
            ['products.create' => 'deny', 'products.delete' => 'deny', 'products.update' => 'deny', 'products.view' => 'deny'],
            $this->overridesOf($staff),
        );

        // And nobody's effective capabilities moved.
        $this->assertSame($before['staff'], $this->currentCapabilities($staff));
        $this->assertSame($before['bookkeeper'], $this->currentCapabilities($bookkeeper));
    }

    /** @test */
    public function no_retired_manage_slug_survives_anywhere_in_the_active_state(): void
    {
        $business = Business::factory()->create();
        $this->seedLegacyCatalogueWithoutGrants();

        $role = $this->roleWith($business, 'everything', self::COARSE_SLUGS);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        foreach (self::COARSE_SLUGS as $slug) {
            $this->override($user, $slug, UserPermission::EFFECT_ALLOW);
        }

        $this->runMigration();

        $surviving = DB::table('permissions')->pluck('slug')
            ->filter(fn (string $slug): bool => str_ends_with($slug, '.manage'))
            ->values()
            ->all();

        $this->assertSame([], $surviving, 'No retired `<resource>.manage` row may remain.');
        $this->assertSame(0, $this->coarseRoleGrantCount());
        $this->assertSame(0, $this->coarseOverrideCount());

        $grantedSlugs = $this->slugsOf($role->fresh());
        $overriddenSlugs = array_keys($this->overridesOf($user));

        foreach (array_merge($grantedSlugs, $overriddenSlugs) as $slug) {
            $this->assertStringNotContainsString('.manage', $slug, "{$slug} must not survive anywhere.");
        }
    }

    /**
     * Execute Step 5's historical expansion migration.
     *
     * Used by the upgrade-path test only, to reproduce the documented sequence
     * `old catalogue → Step 5 → Step 7`. That migration is not modified by this
     * step; it is executed here exactly as it was written.
     */
    private function runStepFive(): void
    {
        $migration = require database_path('migrations/2026_10_01_200000_expand_coarse_manage_role_permissions.php');

        $migration->up();
    }

    /**
     * The `slug => effect` map a migrated override set should produce, sorted to
     * match {@see self::overridesOf()}'s `ORDER BY permissions.slug`.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, string>
     */
    private function expectedOverrides(array $slugs, string $effect): array
    {
        $map = array_fill_keys($slugs, $effect);
        ksort($map);

        return $map;
    }

    /** Total number of user override rows. */
    private function overrideCount(): int
    {
        return DB::table('user_permissions')->count();
    }

    /**
     * Any repeated key combination in a table, e.g. a duplicated pivot row.
     *
     * @param  array<int, string>  $columns
     * @return array<int, object>
     */
    private function duplicatePairs(string $table, array $columns): array
    {
        return DB::table($table)
            ->selectRaw(implode(', ', $columns).', COUNT(*) as total')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->all();
    }

    /**
     * The product capabilities, for the fixtures that reason about the product set.
     *
     * @return array<int, string>
     */
    private function productCapabilities(): array
    {
        return [
            PermissionService::PRODUCTS_VIEW,
            PermissionService::PRODUCTS_CREATE,
            PermissionService::PRODUCTS_UPDATE,
            PermissionService::PRODUCTS_DELETE,
        ];
    }

    /**
     * @test
     *
     * `reports.manage` was a dead alias — listed as satisfying `reports.view`, but
     * never seeded. If an installation somehow carries it, the mapping is provably
     * equivalent, so it is migrated like any other coarse row; when it is absent the
     * step does nothing at all for it.
     */
    public function the_dead_reports_alias_is_only_touched_when_it_actually_exists(): void
    {
        $this->seedLegacyCatalogueWithoutGrants();

        $this->assertDatabaseMissing('permissions', ['slug' => 'reports.manage']);

        $this->runMigration();

        $this->assertSame(
            count(PermissionService::catalogueSlugs()),
            DB::table('permissions')->count(),
            'Only the 14 seeded coarse rows are removed; the absent dead alias costs nothing.',
        );

        $business = Business::factory()->create();

        Permission::firstOrCreate(['slug' => 'reports.manage'], ['name' => 'Reports Manage']);
        $role = $this->roleWith($business, 'reporter', ['reports.manage']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->roles()->attach($role);

        $capabilitiesBefore = $this->legacyCapabilities($user);
        $this->assertSame([PermissionService::REPORTS_VIEW], $capabilitiesBefore);

        $this->runMigration();

        $this->assertDatabaseMissing('permissions', ['slug' => 'reports.manage']);
        $this->assertContains('reports.view', $this->slugsOf($role->fresh()));
        $this->assertSame($capabilitiesBefore, $this->currentCapabilities($user));
    }
}
