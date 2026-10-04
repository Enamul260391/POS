<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 7 — retire the coarse `<resource>.manage` permission catalogue.
 *
 * ## Why
 *
 * The catalogue that shipped since the first release was coarse: one
 * `<resource>.manage` row stood in for a whole domain, and
 * `PermissionService::CAPABILITIES` registered it as a *compatibility alias*
 * of every fine-grained capability underneath it. That alias table has since
 * been removed, so nothing authorises through a coarse slug any more and the
 * 14 rows are dead weight: the roles API still lists them, and a grant of one
 * silently authorises nothing.
 *
 * Step 5 (`2026_10_01_200000`) materialised the fine-grained *role* grants each
 * coarse grant implied. This migration completes the job for the data Step 5
 * could not see — user overrides — and then removes the rows themselves.
 *
 * ## Order of operations (this order is the safety property)
 *
 *   1. **Repair role grants.**  Any coarse grant missing one of its canonical
 *      targets gets it, *before* anything is deleted. This protects databases
 *      that somehow skipped Step 5 or applied it partially; it cannot widen
 *      anything, because every target was already implied by the alias.
 *   2. **Migrate user overrides.**  A coarse `user_permissions` row is expanded
 *      into one canonical row per mapped target, preserving `business_id`,
 *      `user_id` and `effect`. This *must* happen before step 5:
 *      `user_permissions.permission_id` is `cascadeOnDelete`, so deleting a
 *      coarse permission would silently destroy the override with it.
 *   3. **Delete coarse `permission_role` rows.**
 *   4. **Delete coarse `user_permissions` rows** (explicit, so the invariant is
 *      testable even where the FK cascade is disabled).
 *   5. **Delete the coarse `permissions` rows.**
 *
 * Everything runs inside one transaction, so a failure at any point leaves the
 * database exactly as it was found rather than half-migrated. All statements
 * are plain DML — no DDL — so the transaction is real on MySQL, PostgreSQL and
 * SQLite alike. (Laravel only wraps a migration in a transaction when the
 * connection supports it; the explicit `DB::transaction()` here does not depend
 * on that, which matters because tests invoke `up()` directly.)
 *
 * ## Collision rule for user overrides
 *
 * A canonical override may already exist for a target, and a second coarse
 * override may map onto the same target. Resolution is order-independent and
 * always the most restrictive outcome:
 *
 *     existing canonical DENY > migrated coarse DENY
 *     > existing canonical ALLOW > migrated coarse ALLOW
 *
 * DENY is never downgraded and ALLOW is never silently promoted, so no user can
 * end up with *more* access than they had before, and a user who was denied a
 * coarse permission stays denied on every permission it stood for. Rows are
 * keyed on the table's own unique `(business_id, user_id, permission_id)` index,
 * so a collision updates in place rather than inserting a duplicate.
 *
 * ## Tenant isolation
 *
 * `business_id` is copied verbatim from the row being migrated; it is never
 * recomputed, never looked up from the user, and never copied between rows. A
 * user therefore only ever gains overrides inside the business that already
 * owned them, and canonical rows are inserted under exactly the same
 * `(business_id, user_id)` pair.
 *
 * ## Idempotency
 *
 * Re-running is safe: the map is keyed by slug and resolved against
 * `permissions`, so once the coarse rows are gone there is nothing to resolve
 * and every step is a no-op. Even mid-flight, `permission_role` inserts are
 * guarded by an existing-pair check and its unique `(permission_id, role_id)`
 * index, and `user_permissions` writes are keyed on its unique
 * `(business_id, user_id, permission_id)` index.
 *
 * ## `down()` is intentionally a no-op
 *
 * By the time this migration is applied, the coarse rows are gone and their
 * grants have been *replaced* by canonical ones — the mapping is many canonical
 * rows to one coarse row, so the reverse direction cannot be recovered. Any
 * attempt to reconstruct it would have to delete canonical rows, and a
 * canonical row is indistinguishable from one granted independently.
 * Re-creating the coarse rows would leave two overlapping taxonomies in place,
 * and collapsing the migrated overrides would risk erasing an independent
 * ALLOW or downgrading a DENY. Leaving the canonical state alone is the only
 * non-destructive option.
 */
return new class extends Migration
{
    /**
     * Retired coarse permission slug => the canonical slugs that replace it.
     *
     * Each target is the canonical slug of a capability the coarse slug already
     * satisfied through the historical alias table — the same frozen model the
     * Step 5 migration test asserts against — so every role that held the
     * coarse grant keeps every capability it had.
     *
     * Two entries cross domains on purpose, because the aliases did:
     * `expenses.manage` also satisfied `expense_categories.*`, and
     * `roles.manage` also satisfied `permissions.view` and
     * `users.roles.update`.
     *
     * `inventory.manage` and `cash_registers.manage` map onto the slugs that
     * replaced them as their own canonical slug (`inventory.adjust` and
     * `cash_registers.transactions.create`). The capability *names* are
     * unchanged — they are API surface — but the coarse rows can no longer
     * authorise them.
     *
     * @var array<string, array<int, string>>
     */
    private const CANONICAL_TARGETS = [
        'products.manage' => [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
        ],
        'sales.manage' => [
            'sales.view',
            'sales.create',
            'sales.update',
            'sales.delete',
            'sales.payments.create',
        ],
        'purchases.manage' => [
            'purchases.view',
            'purchases.create',
            'purchases.update',
            'purchases.delete',
        ],
        'customers.manage' => [
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
        ],
        'suppliers.manage' => [
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'suppliers.delete',
        ],
        'expenses.manage' => [
            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
            // Cross-domain, and preserved exactly: the coarse row was a real
            // alias for these before it was retired.
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',
        ],
        'users.manage' => [
            'users.view',
            'users.create',
            'users.update',
            'users.roles.update',
        ],
        'roles.manage' => [
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            // Cross-domain.
            'permissions.view',
            'users.roles.update',
        ],
        'settings.manage' => [
            'settings.view',
            'settings.update',
        ],
        'inventory.manage' => [
            'inventory.view',
            // Replaces the coarse row as the backing slug of the
            // `inventory.manage` capability.
            'inventory.adjust',
        ],
        'cash_registers.manage' => [
            'cash_registers.view',
            'cash_registers.create',
            'cash_registers.update',
            // Replaces the coarse row as the backing slug of the
            // `cash_registers.manage` capability.
            'cash_registers.transactions.create',
        ],
        'categories.manage' => [
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
        ],
        'sale_returns.manage' => [
            'sale_returns.view',
            'sale_returns.create',
        ],
        'expense_categories.manage' => [
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',
        ],
    ];

    /**
     * Slugs that were never seeded but that the old alias table recognised.
     *
     * `reports.manage` was a dead alias: it was listed as satisfying
     * `reports.view`, but no release ever created the row, so no installation
     * should have it. It is handled here *only* if the row is actually present,
     * and only because the mapping is provably equivalent — the alias list is a
     * recorded historical fact, so replacing it with the single capability it
     * satisfied cannot change any outcome.
     *
     * Kept out of {@see self::CANONICAL_TARGETS} so the "all 14 coarse rows are
     * gone" invariant and the expected catalogue size are unaffected by an
     * installation that happens to carry this one extra row.
     *
     * @var array<string, array<int, string>>
     */
    private const DEAD_ALIAS_TARGETS = [
        'reports.manage' => ['reports.view'],
    ];

    /** Mirrors `UserPermission::EFFECT_*`; asserted equal by the test suite. */
    private const EFFECT_ALLOW = 'allow';

    private const EFFECT_DENY = 'deny';

    /** Rows written per statement, to stay inside driver placeholder limits. */
    private const INSERT_CHUNK = 500;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->retire();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty. See the class docblock: the coarse → canonical
     * mapping is many-to-one and therefore not reversible, and every
     * reconstruction would destroy authorization state it cannot prove it owns.
     */
    public function down(): void
    {
        // No-op on purpose. See the class docblock above.
    }

    /**
     * Do the whole cleanup as one ordered unit.
     */
    private function retire(): void
    {
        $retirable = $this->retirablePermissionIds();

        if ($retirable === []) {
            return;
        }

        // 1. Guarantee every coarse role grant has its complete canonical set
        //    before a single row is removed.
        foreach ($retirable as $coarseId => $targetIds) {
            $this->ensureRoleGrants($coarseId, $targetIds);
        }

        // 2. Expand the user overrides (must precede step 5: the FK cascades).
        $this->migrateUserOverrides($retirable);

        $coarseIds = array_keys($retirable);

        // 3. The coarse grants themselves.
        DB::table('permission_role')->whereIn('permission_id', $coarseIds)->delete();

        // 4. The coarse overrides. Redundant under the FK cascade, but stated
        //    explicitly so the post-condition is asserted on the pivot rather
        //    than inferred from the catalogue.
        DB::table('user_permissions')->whereIn('permission_id', $coarseIds)->delete();

        // 5. The catalogue rows, last.
        DB::table('permissions')->whereIn('id', $coarseIds)->delete();
    }

    /**
     * The coarse permission ids this run may delete, keyed by id.
     *
     * A permission is only retired when **every** canonical slug it maps to is
     * present in this installation's catalogue. Dropping a coarse row whose
     * replacement is missing would destroy authorization instead of migrating
     * it, and this migration never inserts a permission row of its own — it
     * expands grants into a catalogue that already exists. A database in that
     * state is skipped rather than half-upgraded; re-running once the missing
     * permission has been seeded completes the cleanup.
     *
     * @return array<int, array<int, int>> coarse permission id => target ids
     */
    private function retirablePermissionIds(): array
    {
        $coarseIds = $this->permissionIdsBySlug(array_keys(self::CANONICAL_TARGETS));

        // `reports.manage` is only ever touched when the row actually exists.
        foreach ($this->permissionIdsBySlug(array_keys(self::DEAD_ALIAS_TARGETS)) as $slug => $id) {
            if (! isset($coarseIds[$slug])) {
                $coarseIds[$slug] = $id;
            }
        }

        $retirable = [];

        foreach ($coarseIds as $slug => $coarseId) {
            $targetSlugs = self::CANONICAL_TARGETS[$slug] ?? self::DEAD_ALIAS_TARGETS[$slug];
            $targetIds = $this->permissionIdsBySlug($targetSlugs);

            if (count($targetIds) !== count($targetSlugs)) {
                continue;
            }

            $retirable[$coarseId] = array_values($targetIds);
        }

        return $retirable;
    }

    /**
     * Grant every canonical target to every role that holds the coarse row.
     *
     * Purely additive and defensive: it repairs a partially applied Step 5 and
     * is a no-op when Step 5 ran completely. Roles keep their own `role_id`, so
     * nothing crosses a tenant boundary, and only missing pairs are inserted so
     * the unique `(permission_id, role_id)` index is never violated.
     *
     * @param  array<int, int>  $targetIds
     */
    private function ensureRoleGrants(int $coarseId, array $targetIds): void
    {
        $roleIds = DB::table('permission_role')
            ->where('permission_id', $coarseId)
            ->distinct()
            ->pluck('role_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($roleIds === []) {
            return;
        }

        $existing = $this->existingRolePairs($roleIds, $targetIds);
        $now = now();
        $rows = [];

        foreach ($roleIds as $roleId) {
            foreach ($targetIds as $targetId) {
                if (isset($existing[$roleId][$targetId])) {
                    continue;
                }

                $rows[] = [
                    'permission_id' => $targetId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('permission_role')->insert($chunk);
        }
    }

    /**
     * Replace every coarse override with equivalent canonical overrides.
     *
     * @param  array<int, array<int, int>>  $retirable
     */
    private function migrateUserOverrides(array $retirable): void
    {
        $overrides = DB::table('user_permissions')
            ->whereIn('permission_id', array_keys($retirable))
            ->orderBy('id')
            ->get(['id', 'business_id', 'user_id', 'permission_id', 'effect']);

        foreach ($overrides as $override) {
            foreach ($retirable[(int) $override->permission_id] as $targetId) {
                $this->materializeOverride(
                    (int) $override->business_id,
                    (int) $override->user_id,
                    $targetId,
                    (string) $override->effect,
                );
            }
        }
    }

    /**
     * Write one canonical override, resolving any collision conservatively.
     *
     * `existing canonical DENY > migrated coarse DENY > existing canonical ALLOW
     * > migrated coarse ALLOW`. Anything that is not `allow` is treated as a
     * DENY, so an unrecognised effect fails closed rather than granting access
     * the old resolver would not have granted either.
     */
    private function materializeOverride(int $businessId, int $userId, int $permissionId, string $effect): void
    {
        $existing = DB::table('user_permissions')
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('permission_id', $permissionId)
            ->first();

        if ($existing === null) {
            DB::table('user_permissions')->insert([
                'business_id' => $businessId,
                'user_id' => $userId,
                'permission_id' => $permissionId,
                'effect' => $this->normaliseEffect($effect),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // An existing DENY is authoritative and is never downgraded.
        if ($existing->effect === self::EFFECT_DENY) {
            return;
        }

        // Existing ALLOW: only a migrated DENY outranks it.
        if ($this->normaliseEffect($effect) !== self::EFFECT_DENY) {
            return;
        }

        DB::table('user_permissions')
            ->where('id', $existing->id)
            ->update([
                'effect' => self::EFFECT_DENY,
                'updated_at' => now(),
            ]);
    }

    private function normaliseEffect(string $effect): string
    {
        return $effect === self::EFFECT_ALLOW ? self::EFFECT_ALLOW : self::EFFECT_DENY;
    }

    /**
     * Resolve the given slugs to their permission ids, keyed by slug.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, int>
     */
    private function permissionIdsBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The `(permission_id, role_id)` pairs already present for these roles.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, int>  $permissionIds
     * @return array<int, array<int, bool>>
     */
    private function existingRolePairs(array $roleIds, array $permissionIds): array
    {
        $existing = DB::table('permission_role')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->get(['role_id', 'permission_id']);

        $indexed = [];

        foreach ($existing as $row) {
            $indexed[(int) $row->role_id][(int) $row->permission_id] = true;
        }

        return $indexed;
    }
};
