<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-level first Business Admin provisioning.
 *
 * This service handles the secure creation of the first Business Admin for a
 * business. It is the platform counterpart to the tenant staff directory
 * (UserService) but with additional safeguards:
 *
 * - The target business must exist and be eligible (no existing admin)
 * - The 'admin' role is resolved server-side for the route business
 * - The operation is fully transactional (user + role pivot written together)
 * - Platform identity fields are never writable
 * - Duplicate admin provisioning is prevented at the database level
 *
 * Callers are the `/api/v1/platform/businesses/{business}/admin` endpoint,
 * which sits behind `auth:sanctum` + `active` + `platform` and the
 * `platform.businesses.admin.create` Gate.
 */
final class BusinessAdminProvisioningService
{
    public function __construct(
        private readonly BusinessService $businesses,
    ) {}

    /**
     * Resolve the route business, or 404 (soft-deleted reads as missing).
     *
     * @throws ModelNotFoundException
     */
    public function business(int $businessId): Business
    {
        return $this->businesses->find($businessId);
    }

    /**
     * Provision the first Business Admin for the given business.
     *
     * The method:
     * 1. Locks the business row (`FOR UPDATE`) as the FIRST statement of the
     *    transaction, so two simultaneous provisioning requests for the same
     *    business serialise on that row instead of racing each other
     * 2. Confirms the business has no existing admin user — the check runs
     *    only after any in-flight provisioning for this business has committed
     * 3. Resolves or creates the 'admin' role for that business
     * 4. Creates the user with the admin role in a single transaction
     *
     * The row lock is the smallest safe mechanism for the check-then-insert
     * race: no database constraint can express "at most one admin user per
     * business" (`role_user` is unique per user+role, not per business), and
     * it mirrors the pattern {@see \App\Services\Auth\AuthService::issueSoleToken}
     * already uses to serialise single-login. On SQLite the lock compiles to a
     * no-op (single-writer engine), so the test suite is unaffected.
     *
     * @param  array<string, mixed>  $data  Validated platform payload (from StoreBusinessAdminRequest::userData())
     *
     * @throws BusinessRuleException when the business already has an admin.
     */
    public function provisionFirstAdmin(int $businessId, array $data): User
    {
        return DB::transaction(function () use ($businessId, $data): User {
            // Row lock FIRST — before any consistent read in this transaction —
            // so a concurrent request blocks here until the first one commits
            // and the admin-existence check below then observes the committed
            // admin. The soft-delete scope still excludes deleted businesses,
            // so a missing/deleted business fails 404 exactly as before.
            $business = Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();

            // Confirm eligibility: no existing admin for this business
            $this->assertNoExistingAdmin($business);

            // Resolve or create the 'admin' role for this business (server-side, never from client)
            $adminRole = $this->resolveOrCreateAdminRole($businessId);

            // Create the user with the admin role in one transaction
            $user = User::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'branch' => $data['branch'] ?? null,
                'status' => $data['status'] ?? User::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
                'password' => $data['password'],
                // Platform identity is explicitly NULL - never a Platform Owner
                'platform_role' => null,
            ]);

            // Attach the admin role (pivot written in same transaction)
            $user->roles()->sync([$adminRole->getKey()]);

            return $user->load('roles.permissions');
        });
    }

    /**
     * Resolve the admin role for the business, creating it with all permissions if it doesn't exist.
     */
    private function resolveOrCreateAdminRole(int $businessId): Role
    {
        $adminRole = Role::query()
            ->forBusiness($businessId)
            ->where('slug', 'admin')
            ->first();

        if ($adminRole) {
            return $adminRole;
        }

        // Admin role doesn't exist - create it with all permissions
        // This handles businesses created without default roles (e.g., via factories in tests)
        return DB::transaction(function () use ($businessId): Role {
            // Ensure all permissions from the catalogue exist
            $this->ensurePermissionsExist();

            $adminRole = Role::create([
                'business_id' => $businessId,
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full access to all features',
            ]);

            $permissionIds = Permission::query()->pluck('id')->all();

            if ($permissionIds !== []) {
                $now = now();
                DB::table('permission_role')->insert(array_map(
                    static fn (int $permissionId): array => [
                        'permission_id' => $permissionId,
                        'role_id' => $adminRole->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $permissionIds,
                ));
            }

            return $adminRole;
        });
    }

    /**
     * Ensure all permissions from the canonical catalogue exist.
     * Mirrors RolesAndPermissionsSeeder::permissionCatalogue() logic.
     */
    private function ensurePermissionsExist(): void
    {
        $catalogue = [];

        foreach (PermissionService::catalogueSlugs() as $slug) {
            $catalogue[$slug] = Str::headline($slug);
        }

        // Override display names for slugs with established names
        $names = [
            'reports.view' => 'View Reports',
            'inventory.adjust' => 'Adjust Stock',
            'cash_registers.transactions.create' => 'Record Cash Movement',
            'cash_registers.transactions.update' => 'Edit Cash Movement',
            'cash_registers.transactions.delete' => 'Delete Cash Movement',
        ];

        $catalogue = array_merge($catalogue, $names);

        foreach ($catalogue as $slug => $name) {
            Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $name],
            );
        }
    }

    /**
     * Assert that the business has no existing admin user.
     *
     * An "admin" is a user of this business holding the 'admin' role of this
     * business — the very definition {@see \App\Models\Business::adminUsers()}
     * publishes as `has_admin`. Both sides therefore read the same fact, and a
     * stale UI (or a repeated request) can never create a second first admin.
     *
     * @throws BusinessRuleException when an admin already exists.
     */
    private function assertNoExistingAdmin(Business $business): void
    {
        if ($business->adminUsers()->exists()) {
            throw new BusinessRuleException('This business already has an administrator. First admin provisioning can only be performed once.');
        }
    }
}