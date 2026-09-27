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

        // Grant the full catalogue to each business's admin role by default.
        $permissionIds = DB::table('permissions')->pluck('id')->toArray();
        $now = now();

        foreach ($businessIds as $businessId) {
            $adminRoleId = DB::table('roles')
                ->where('business_id', $businessId)
                ->where('slug', 'admin')
                ->value('id');

            if (! $adminRoleId) {
                continue;
            }

            $rolePermissionData = [];
            foreach ($permissionIds as $permissionId) {
                $rolePermissionData[] = [
                    'permission_id' => $permissionId,
                    'role_id' => $adminRoleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rolePermissionData === []) {
                continue;
            }

            // `permission_role` has a unique (permission_id, role_id) index, so
            // a plain insert would fail on a re-run. Only missing pairs are
            // inserted, which keeps the seeder repeatable without duplicating
            // pivot rows.
            $existing = DB::table('permission_role')
                ->where('role_id', $adminRoleId)
                ->pluck('permission_id')
                ->all();

            $missing = array_values(array_diff($permissionIds, $existing));

            if ($missing === []) {
                continue;
            }

            DB::table('permission_role')->insert(
                array_values(array_filter(
                    $rolePermissionData,
                    static fn (array $row): bool => in_array($row['permission_id'], $missing, true),
                ))
            );
        }
    }

    /**
     * The global permission catalogue: slug => display name.
     *
     * The `<resource>.manage` entries are the original convention and are kept
     * for backwards compatibility (Phase 24B still accepts them as aliases).
     * The fine-grained entries are the capabilities `PermissionService`
     * authorises against, so a business can grant exactly one action instead of
     * blanket access.
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
        ];

        $capabilities = [];

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $capabilities[$capability] = Str::headline($capability);
        }

        return array_merge($existing, $capabilities);
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
