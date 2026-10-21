<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STEP 4 — least-privilege batch read for the POS price selector.
 *
 * Adds the global `inventory.batches.view` permission (resolving the
 * `inventory.batches.view` capability) and grants it to every role that can
 * already create sales, plus admin. Additive and idempotent: only missing
 * permission rows and missing (permission_id, role_id) pairs are inserted.
 * No grants are removed and no existing rows are modified, so tenant
 * isolation is preserved (grants attach to the same role_id that already
 * holds sales.create).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->where('slug', 'inventory.batches.view')->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'View Inventory Batches',
                'slug' => 'inventory.batches.view',
                'description' => 'View Inventory Batches',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $salesCreateId = DB::table('permissions')->where('slug', 'sales.create')->value('id');

        $targetRoleIds = [];
        if ($salesCreateId !== null) {
            $targetRoleIds = DB::table('permission_role')
                ->where('permission_id', $salesCreateId)
                ->distinct()
                ->pluck('role_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        // Admin keeps the whole catalogue: any admin-slug role is included
        // even if it does not hold sales.create directly.
        $adminRoleIds = DB::table('roles')
            ->where('slug', 'admin')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $grantRoleIds = array_values(array_unique(array_merge($targetRoleIds, $adminRoleIds)));

        // Fallback: if sales.create is not seeded yet, grant to admin roles
        // only rather than inventing a wider grant.
        if ($grantRoleIds === []) {
            $grantRoleIds = $adminRoleIds;
        }

        if ($grantRoleIds === []) {
            return;
        }

        $existing = DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $grantRoleIds)
            ->pluck('role_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $existingMap = array_fill_keys($existing, true);
        $rows = [];
        foreach ($grantRoleIds as $roleId) {
            if (isset($existingMap[$roleId])) {
                continue;
            }
            $rows[] = [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('permission_role')->insert($chunk);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'inventory.batches.view')->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
