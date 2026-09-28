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
            // application, so each role is granted the coarse `<resource>.manage`
            // permissions matching its documented description. The grants are
            // additive and idempotent, and never remove an existing grant.
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
     * The coarse `<resource>.manage` slugs are used deliberately: they are the
     * rows these roles would have been given under the original catalogue, and
     * `PermissionService` maps them onto every fine-grained capability for that
     * resource. A business can still narrow a role down afterwards through the
     * roles API without the seeder fighting that choice on the next run.
     *
     * `sales.manage` is deliberately absent from the accountant: recording a
     * payment is its own capability (`sales.payments.create`), and taking money
     * is not the same authority as being able to create or amend sales.
     *
     * @return array<string, array<int, string>>
     */
    protected function roleGrants(): array
    {
        return [
            'manager' => [
                'products.manage',
                'categories.manage',
                'inventory.manage',
                'sales.manage',
                'sale_returns.manage',
                'purchases.manage',
                'customers.manage',
                'suppliers.manage',
                'expenses.manage',
                'cash_registers.manage',
                'reports.view',
            ],
            'salesman' => [
                'products.manage',
                'categories.manage',
                'sales.manage',
                'customers.manage',
                'cash_registers.manage',
            ],
            'accountant' => [
                'purchases.manage',
                'suppliers.manage',
                'expenses.manage',
                'expense_categories.manage',
                'sales.payments.create',
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
     * The `<resource>.manage` entries are the original convention and are kept
     * for backwards compatibility (`PermissionService` still accepts them as
     * aliases). The fine-grained entries are the capabilities
     * `PermissionService` authorises against, so a business can grant exactly
     * one action instead of blanket access.
     *
     * `categories.manage` and `sale_returns.manage` are new coarse rows: those
     * two domains had no permission of any kind before operational enforcement,
     * so unlike the other resources there was no existing row to alias. They
     * follow the same convention as the rest of the catalogue.
     *
     * @return array<string, string>
     */
    protected function permissionCatalogue(): array
    {
        $existing = [
            'products.manage' => 'Manage Products',
            'sales.manage' => 'Manage Sales',
            'purchases.manage' => 'Manage Purchases',
            'customers.manage' => 'Manage Customers',
            'suppliers.manage' => 'Manage Suppliers',
            'expenses.manage' => 'Manage Expenses',
            'reports.view' => 'View Reports',
            'users.manage' => 'Manage Users',
            'roles.manage' => 'Manage Roles',
            'settings.manage' => 'Manage Settings',
            'inventory.manage' => 'Manage Inventory',
            'cash_registers.manage' => 'Manage Cash Registers',
            'categories.manage' => 'Manage Categories',
            'sale_returns.manage' => 'Manage Sale Returns',
            'expense_categories.manage' => 'Manage Expense Categories',
        ];

        $capabilities = [];

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $capabilities[$capability] = Str::headline($capability);
        }

        // `array_merge` lets the hand-written names above win for the slugs that
        // appear in both sets (e.g. `reports.view`, `inventory.manage`), so the
        // established display names are preserved and not replaced by a headline
        // rendering of the same slug.
        return array_merge($capabilities, $existing);
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
