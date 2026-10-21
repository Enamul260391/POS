<?php

namespace Database\Seeders;

use App\Services\Permission\PermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Default roles for the POS system.
     */
    public function run(): void
    {
        $roles = [
            'admin' => 'Full access to all features',
            'manager' => 'Manage products, sales, purchases, and reports',
            'salesman' => 'Handle sales and customer management',
            'accountant' => 'Manage purchases, expenses, and payments',
        ];

        $businessIds = DB::table('businesses')->pluck('id');

        foreach ($businessIds as $businessId) {
            foreach ($roles as $slug => $description) {
                $this->upsertRole((int) $businessId, $slug, $description);
            }
        }

        foreach ($this->permissionCatalogue() as $slug => $name) {
            $this->upsertPermission($slug, $name);
        }

        $permissionIds = DB::table('permissions')->pluck('id')->toArray();

        foreach ($businessIds as $businessId) {
            $businessId = (int) $businessId;

            // Admin keeps the whole catalogue, exactly as before.
            $this->grantMissingPermissions(
                $this->roleId($businessId, 'admin'),
                $permissionIds,
            );

            // The other seeded roles used to receive no permissions at all,
            // which was harmless while the operational endpoints were only
            // authenticated. Now that they are capability-gated, leaving those
            // roles empty would strip every non-admin user of the whole
            // application, so each role is granted the fine-grained permissions
            // matching its documented description (see `roleGrants()`). The grants
            // are additive and idempotent, and never remove an existing grant.
            foreach ($this->roleGrants() as $slug => $slugs) {
                $this->grantMissingPermissions(
                    $this->roleId($businessId, $slug),
                    $this->permissionIdsFor($slugs),
                );
            }
        }
    }

    /**
     * The permission slugs each non-admin seeded role receives.
     *
     * Derived from the role descriptions declared in `run()` — nothing is
     * invented here:
     *   - manager    -> "Manage products, sales, purchases, and reports"
     *   - salesman   -> "Handle sales and customer management"
     *   - accountant -> "Manage purchases, expenses, and payments"
     *
     * Each list states the exact actions, verb by verb. These roles used to
     * receive a single coarse `<resource>.manage` row per domain and rely on
     * `PermissionService` to fan that out across every verb; that alias table is
     * gone, so the verbs the coarse row used to imply are now written out here.
     * The lists are therefore *equivalent* to the old grants, not narrower and
     * not wider — the expansion is exactly what STEP 6 materialised into
     * `permission_role` for these same roles.
     *
     * Two consequences are worth stating explicitly, because both look like an
     * omission otherwise:
     *
     *   - The manager receives `expense_categories.*` without ever naming
     *     expenses' categories in its description. It always had it: the
     *     coarse `expenses.manage` row was an alias for `expense_categories.*`
     *     as well as `expenses.*`, and that cross-domain grant was preserved
     *     during the Step 6 migration. Dropping it here would silently reduce
     *     what a freshly seeded manager can do compared with an upgraded one.
     *   - The manager and salesman receive `sales.payments.create`, because
     *     their `sales.manage` row aliased it. The accountant receives it
     *     explicitly instead, which is the whole point of that role: recording
     *     a payment is its own capability.
     *
     * `sales.create` is deliberately absent from the accountant — taking money
     * is not the same authority as being able to raise or amend a sale.
     *
     * The grants are additive and idempotent: `grantMissingPermissions()` only
     * inserts pairs that are absent, so a business can still narrow a seeded
     * role down afterwards through the roles API without the seeder undoing that
     * on its next run.
     *
     * @return array<string, array<int, string>>
     */
    protected function roleGrants(): array
    {
        return [
            'manager' => [
                // Products.
                'products.view', 'products.create', 'products.update', 'products.delete',
                // Categories.
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                // Sales, including recording a payment against one.
                'sales.view', 'sales.create', 'sales.update', 'sales.delete',
                'sales.payments.create',
                // POS batch price options (least-privilege batch read).
                'inventory.batches.view',
                // Sale returns.
                'sale_returns.view', 'sale_returns.create',
                // Purchases and suppliers.
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
                'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
                // Customers.
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                // Expenses, and the categories they are filed under.
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'expense_categories.view', 'expense_categories.create',
                'expense_categories.update', 'expense_categories.delete',
                // Stock: reading the ledger and correcting it.
                'inventory.view', 'inventory.adjust',
                // Cash drawer: reading, opening, closing, manual movements.
                // Correcting or removing a manual movement is the same authority
                // as recording one, so the roles that already hold
                // `cash_registers.transactions.create` (manager and salesman
                // below) hold the two corrections with it. The accountant has no
                // cash-drawer capability at all and is therefore given neither.
                'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
                'cash_registers.transactions.create',
                'cash_registers.transactions.update',
                'cash_registers.transactions.delete',
                // Reporting.
                'reports.view',
            ],

            'salesman' => [
                // Products.
                'products.view', 'products.create', 'products.update', 'products.delete',
                // Categories.
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                // Sales, including recording a payment against one.
                'sales.view', 'sales.create', 'sales.update', 'sales.delete',
                'sales.payments.create',
                // POS batch price options (least-privilege batch read).
                'inventory.batches.view',
                // Customers.
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                // Cash drawer: reading, opening, closing, manual movements —
                // recording one and correcting/removing one alike.
                'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
                'cash_registers.transactions.create',
                'cash_registers.transactions.update',
                'cash_registers.transactions.delete',
            ],

            'accountant' => [
                // Purchases and suppliers.
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
                'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
                // Expenses, and the categories they are filed under.
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'expense_categories.view', 'expense_categories.create',
                'expense_categories.update', 'expense_categories.delete',
                // Recording a payment, but not raising or amending a sale.
                'sales.payments.create',
                // Reporting.
                'reports.view',
            ],
        ];
    }

    /**
     * Resolve the given permission slugs to their ids, skipping unknown ones.
     *
     * A slug that is not in the catalogue yet simply grants nothing rather than
     * failing the seed, so an installation is never blocked by a missing row.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    protected function permissionIdsFor(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The id of one of this business's seeded roles, if it exists.
     */
    protected function roleId(int $businessId, string $slug): ?int
    {
        $id = DB::table('roles')
            ->where('business_id', $businessId)
            ->where('slug', $slug)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Insert only the (permission, role) pairs that do not exist yet.
     *
     * `permission_role` carries a unique `(permission_id, role_id)` index, so a
     * plain insert of every pair would fail on a re-run. Only the missing pairs
     * are written, which keeps the seeder repeatable, never duplicates pivot
     * rows, and never revokes a grant a business made on purpose.
     *
     * @param  array<int, int>  $permissionIds
     */
    protected function grantMissingPermissions(?int $roleId, array $permissionIds): void
    {
        if ($roleId === null || $permissionIds === []) {
            return;
        }

        $existing = DB::table('permission_role')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->all();

        $missing = array_values(array_diff($permissionIds, $existing));

        if ($missing === []) {
            return;
        }

        $now = now();

        DB::table('permission_role')->insert(array_map(
            static fn (int $permissionId): array => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $missing,
        ));
    }

    /**
     * The global permission catalogue: slug => display name.
     *
     * Exactly the canonical slug of every capability in
     * {@see PermissionService::CAPABILITIES} — no more, no less. The 14 coarse
     * `<resource>.manage` rows the original catalogue shipped with are gone:
     * they were never permissions in their own right, only the convention that
     * stood in for a set of fine-grained ones, and
     * `2026_10_01_300000_remove_coarse_manage_permissions` first materialised
     * every grant they implied into real `permission_role` rows (including the
     * `expenses.manage -> expense_categories.*` and
     * `roles.manage -> permissions.view` cross-domain relationships) before
     * dropping them.
     *
     * The set is derived from {@see PermissionService::catalogueSlugs()} rather
     * than restated, so a capability added there is seeded automatically and the
     * seeder can never drift back into creating a permission nothing authorises
     * against. A permission row that no capability resolves to would be dead
     * weight the roles API would still happily offer a tenant.
     *
     * Two slugs read as awkward when headline-cased because their capability
     * name differs from the slug (see `INVENTORY_MANAGE` and
     * `CASH_REGISTERS_MANAGE`), so their display names are pinned below rather
     * than machine-generated.
     *
     * @return array<string, string>
     */
    protected function permissionCatalogue(): array
    {
        $catalogue = [];

        foreach (PermissionService::catalogueSlugs() as $slug) {
            $catalogue[$slug] = Str::headline($slug);
        }

        // `reports.view` is the one slug whose established display name differs
        // from a headline rendering of itself ("View Reports", not
        // "Reports View"). It is kept verbatim so the roles UI does not show a
        // renamed permission that has existed under that label since the first
        // release. The two slugs below did not exist before Step 6 and so have
        // no established name to preserve; theirs describe the action, because
        // their capability names (`inventory.manage`,
        // `cash_registers.manage`) no longer say what the write actually is.
        $names = [
            'reports.view' => 'View Reports',
            'inventory.adjust' => 'Adjust Stock',
            'cash_registers.transactions.create' => 'Record Cash Movement',
            'cash_registers.transactions.update' => 'Edit Cash Movement',
            'cash_registers.transactions.delete' => 'Delete Cash Movement',
        ];

        return array_merge($catalogue, $names);
    }

    /**
     * Create a role only when this business does not already have that slug.
     */
    protected function upsertRole(int $businessId, string $slug, string $description): void
    {
        $exists = DB::table('roles')
            ->where('business_id', $businessId)
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('roles')->insert([
            'business_id' => $businessId,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create a permission only when the slug is not already defined.
     */
    protected function upsertPermission(string $slug, string $name): void
    {
        $exists = DB::table('permissions')->where('slug', $slug)->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => $name,
            'slug' => $slug,
            'description' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
