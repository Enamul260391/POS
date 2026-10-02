<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 5 — expand existing coarse `<resource>.manage` role grants into their
 * fine-grained equivalents.
 *
 * ## Why
 *
 * `PermissionService::CAPABILITIES` authorises against fine-grained capability
 * names (`products.view`, `sales.create`, ...), while the catalogue that has
 * shipped since the first release is coarse (`products.manage`, `sales.manage`,
 * ...). Each coarse permission is therefore registered as a *compatibility
 * alias* of the fine capabilities it covers, so a role seeded years ago keeps
 * working untouched.
 *
 * Those aliases are a compatibility shim, not the target architecture. This
 * migration materialises the fine-grained permissions each coarse grant already
 * *implies*, so a later step can drop the alias table without changing what any
 * role can do.
 *
 * ## What it adds
 *
 * For every `(role_id, coarse permission_id)` row in `permission_role`, the
 * equivalent fine-grained permissions are inserted **for that same role**.
 *
 * The expansion map below is not a guess: it is exactly the set of
 * `PermissionService::CAPABILITIES` entries whose alias list contains the coarse
 * slug *and* whose canonical slug lives in the same resource domain. That last
 * restriction is what makes the migration provably safe — every slug it adds was
 * **already satisfied** by the coarse row through the alias, so the role's
 * effective capability set is identical before and after.
 *
 * ## What it deliberately does NOT add
 *
 * Cross-domain aliases, which are left for a later alias-cleanup step:
 *
 *   - `expenses.manage` also satisfies `expense_categories.*`
 *   - `roles.manage` also satisfies `permissions.view` and `users.roles.update`
 *
 * Those are real, load-bearing behaviours, but they are *not* the direct
 * semantic equivalent of the coarse permission that happens to alias them, and
 * silently rewriting them here would change the meaning of unrelated domains.
 *
 * Self-canonical coarse permissions contribute nothing to their own expansion:
 * `inventory.manage` and `cash_registers.manage` are themselves the canonical
 * slugs for the "manual stock adjustment" and "manual cash movement" write
 * capabilities, so there is no finer permission to add. Their dead aliases
 * (`inventory.adjust`, `cash_registers.transactions.create`) are never seeded
 * and are never created here.
 *
 * ## Guarantees
 *
 * - **Additive.** No permission row, role, user, business, `role_user` row or
 *   `user_permissions` override is deleted or modified. Coarse rows stay
 *   exactly where they are, which is what gives a safe verification window.
 * - **Idempotent.** Only missing `(permission_id, role_id)` pairs are inserted,
 *   so re-running adds nothing. The existing unique index on those two columns
 *   is the backstop if the same migration is ever executed twice concurrently.
 * - **Tenant safe.** Permissions are global but roles are business-scoped. Rows
 *   are only ever added to the *same* `role_id` that already held the coarse
 *   grant, so a permission can never leak from one business into another.
 * - **Catalogue safe.** Fine-grained permissions are looked up by slug and
 *   skipped when absent, so the migration never inserts a permission row and
 *   never invents a slug.
 */
return new class extends Migration
{
    /**
     * Coarse permission slug => the fine-grained slugs it expands into.
     *
     * Every entry satisfies two properties, both asserted by
     * `CoarseManagePermissionExpansionMigrationTest`:
     *
     *   1. Each target is the canonical (first) slug of a capability that the
     *      coarse slug already satisfies through `PermissionService::CAPABILITIES`,
     *      so the grant is pre-existing rather than new.
     *   2. Each target lives in the same resource domain as the coarse slug,
     *      which excludes the cross-domain aliases documented above.
     *
     * @var array<string, array<int, string>>
     */
    private const EXPANSION = [
        'products.manage' => [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
        ],
        // `sales.manage` has always satisfied `sales.payments.create` (see
        // `PermissionService::CAPABILITIES`), so it is preserved here. Removing
        // it would narrow exactly the roles this migration must not change.
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
        ],
        'settings.manage' => [
            'settings.view',
            'settings.update',
        ],
        // No `inventory.create/update/delete` exists or is implied: a manual
        // stock adjustment *is* the coarse `inventory.manage` capability.
        'inventory.manage' => [
            'inventory.view',
        ],
        // No `cash_registers.delete` exists, and the manual cash-movement
        // capability *is* `cash_registers.manage` itself.
        'cash_registers.manage' => [
            'cash_registers.view',
            'cash_registers.create',
            'cash_registers.update',
        ],
        'categories.manage' => [
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
        ],
        // Only `view` and `create` exist for sale returns.
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

    /** Rows inserted per statement, to stay well inside driver placeholder limits. */
    private const INSERT_CHUNK = 500;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $coarseIds = $this->permissionIdsBySlug(array_keys(self::EXPANSION));

        if ($coarseIds === []) {
            return;
        }

        foreach ($coarseIds as $slug => $coarseId) {
            $this->expandGrant($coarseId, self::EXPANSION[$slug]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally non-destructive.
     *
     * The rows this migration inserts are indistinguishable from fine-grained
     * rows that already existed beforehand or were granted independently
     * afterwards — `permission_role` carries no marker identifying their origin.
     * A `down()` that deleted every `(coarse -> fine)` pair would therefore
     * silently revoke legitimate, unrelated grants, which is strictly worse than
     * leaving the extra grants in place.
     *
     * Leaving them is safe *and* correct: the coarse rows this migration
     * expanded are deliberately left in place, so every role keeps its original
     * authorisation through them regardless. That makes the operation a no-op
     * semantically, leaving only rows a future, narrower step should decide on.
     */
    public function down(): void
    {
        // No-op on purpose. See the method docblock above.
    }

    /**
     * Expand one coarse permission's grants across every role holding it.
     *
     * @param  array<int, string>  $fineSlugs
     */
    private function expandGrant(int $coarseId, array $fineSlugs): void
    {
        // Only the roles that *already* hold this coarse permission are touched,
        // and each of them keeps its own `role_id`. That is the whole tenant
        // guarantee: no role is ever granted a permission it did not derive.
        $roleIds = DB::table('permission_role')
            ->where('permission_id', $coarseId)
            ->distinct()
            ->pluck('role_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($roleIds === []) {
            return;
        }

        // Fine-grained permissions that this installation has actually seeded.
        // A missing row simply contributes no grant rather than failing the
        // migration, so an older catalogue is handled without inventing slugs.
        $fineIds = array_values($this->permissionIdsBySlug($fineSlugs));

        if ($fineIds === []) {
            return;
        }

        $existing = $this->existingPairs($roleIds, $fineIds);

        $now = now();
        $rows = [];

        foreach ($roleIds as $roleId) {
            foreach ($fineIds as $fineId) {
                if (isset($existing[$roleId][$fineId])) {
                    continue;
                }

                $rows[] = [
                    'permission_id' => $fineId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->insertInChunks($rows);
    }

    /**
     * Resolve the given slugs to their permission ids, keyed by slug.
     *
     * Permissions are global, so only existence has to be checked.
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
     * Filtered in one query so the migration stays deterministic and cheap:
     * `permission_role` has a unique index on `(permission_id, role_id)`, so an
     * unconditional insert of an existing pair would abort the whole migration.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, int>  $permissionIds
     * @return array<int, array<int, bool>>
     */
    private function existingPairs(array $roleIds, array $permissionIds): array
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

    /**
     * Insert the prepared rows in bounded chunks.
     *
     * @param  array<int, array{permission_id: int, role_id: int, created_at: mixed, updated_at: mixed}>  $rows
     */
    private function insertInChunks(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('permission_role')->insert($chunk);
        }
    }
};
