<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single source of truth for "what may a user do?".
 *
 * Permissions are a **global** catalogue: the `permissions` table has no
 * `business_id`, so a permission is a system-wide definition (`roles.update`)
 * and a business grants a subset of it to its own roles. Because of that this
 * service exposes no create/update/delete â€” the catalogue is seeded
 * (`RolesAndPermissionsSeeder`) and read-only through the API.
 *
 * ## Naming
 * The API authorises against the fine-grained capability names below
 * (`roles.update`, `sales.create`, `products.view`, `inventory.manage`, ...),
 * and every one of them resolves through a single canonical permission slug.
 *
 * The catalogue that shipped originally was coarse (`roles.manage`,
 * `sales.manage`, ...). Those 14 rows existed only as a compatibility shim for
 * the coarse convention and have since been retired: the migration
 * `2026_10_01_300000_remove_coarse_manage_permissions` first materialised every
 * fine-grained grant each coarse row already implied (including the two
 * cross-domain aliases) and migrated any user override that referenced one,
 * then removed the rows themselves. No capability resolves through a
 * `<resource>.manage` slug any more, so a stale `*.manage` grant cannot
 * silently re-authorise anything.
 *
 * Two capability names still *end* in `.manage` — `inventory.manage` and
 * `cash_registers.manage`. Those are capability names, not slugs: they are the
 * route/gate/frontend identifiers for two writes that are genuinely not CRUD
 * verbs (a manual stock adjustment and a manual cash movement) and are therefore
 * backed by the slugs `inventory.adjust` and
 * `cash_registers.transactions.create`, which describe the action itself. The
 * capability names are preserved because the routes and the `capabilities`
 * list published to the client are API surface.
 *
 * Every check runs against the user's *effective* slugs — the permissions held
 * through their roles, plus their ALLOW overrides, minus their DENY overrides
 * (`Effective = (role permissions ∪ user ALLOW) − user DENY`). Both the role
 * resolution and the overrides are memoised on the user instance, so N checks
 * cost at most two queries.
 */
final class PermissionService
{
    /** Read the role catalogue. */
    public const ROLES_VIEW = 'roles.view';

    /** Create roles. */
    public const ROLES_CREATE = 'roles.create';

    /** Edit roles and their permission grants. */
    public const ROLES_UPDATE = 'roles.update';

    /** Delete roles. */
    public const ROLES_DELETE = 'roles.delete';

    /** Read the global permission catalogue. */
    public const PERMISSIONS_VIEW = 'permissions.view';

    /** Read the staff directory. */
    public const USERS_VIEW = 'users.view';

    /** Create staff accounts. */
    public const USERS_CREATE = 'users.create';

    /** Edit staff accounts. */
    public const USERS_UPDATE = 'users.update';

    /** Change which roles a user holds. */
    public const USERS_ROLES_UPDATE = 'users.roles.update';

    /** Read business settings. */
    public const SETTINGS_VIEW = 'settings.view';

    /** Change business settings. */
    public const SETTINGS_UPDATE = 'settings.update';

    // ------------------------------------------------------------------
    // Operational domains
    //
    // Each domain follows the same shape as the administration block above:
    // a fine-grained capability per verb, each backed by its own permission. A
    // role granted `products.view` and nothing else can read the catalogue and
    // do nothing to it; granting `products.delete` is a separate decision.
    //
    // There are no coarse `<resource>.manage` aliases left in this table. A role
    // that was originally seeded with only `products.manage` kept exactly the
    // product access it always had because the fine-grained rows it stood for
    // were materialised for it before the coarse row stopped being read.
    // ------------------------------------------------------------------

    /** Read the product catalogue. */
    public const CATEGORIES_VIEW = 'categories.view';

    /** Create categories. */
    public const CATEGORIES_CREATE = 'categories.create';

    /** Edit categories. */
    public const CATEGORIES_UPDATE = 'categories.update';

    /** Delete categories. */
    public const CATEGORIES_DELETE = 'categories.delete';

    /** Read products. */
    public const PRODUCTS_VIEW = 'products.view';

    /** Create products. */
    public const PRODUCTS_CREATE = 'products.create';

    /** Edit products. */
    public const PRODUCTS_UPDATE = 'products.update';

    /** Delete products. */
    public const PRODUCTS_DELETE = 'products.delete';

    /** Read the stock movement ledger. */
    public const INVENTORY_VIEW = 'inventory.view';

    /**
     * Apply a manual stock adjustment.
     *
     * Deliberately a single capability: stock correction is a ledger write that
     * bypasses the normal purchase/sale flow, so it is never a "read" or an
     * ordinary CRUD verb.
     *
     * The capability *name* is preserved from the original API because the
     * `can:` middleware and the client's `capabilities` list both use it, but it
     * is no longer the permission slug. The slug is {@see INVENTORY_ADJUST},
     * which names the action instead of using the retired `.manage`
     * convention.
     */
    public const INVENTORY_MANAGE = 'inventory.manage';

    /**
     * The permission slug backing {@see INVENTORY_MANAGE}.
     *
     * Introduced by Step 6: the coarse `inventory.manage` row existed only as
     * its own alias, so there was no fine-grained catalogue entry describing
     * the write it performed. This is that entry, and it describes the action.
     */
    public const INVENTORY_ADJUST = 'inventory.adjust';

    /** Read customers. */
    public const CUSTOMERS_VIEW = 'customers.view';

    /** Create customers. */
    public const CUSTOMERS_CREATE = 'customers.create';

    /** Edit customers. */
    public const CUSTOMERS_UPDATE = 'customers.update';

    /** Delete customers. */
    public const CUSTOMERS_DELETE = 'customers.delete';

    /** Read suppliers. */
    public const SUPPLIERS_VIEW = 'suppliers.view';

    /** Create suppliers. */
    public const SUPPLIERS_CREATE = 'suppliers.create';

    /** Edit suppliers. */
    public const SUPPLIERS_UPDATE = 'suppliers.update';

    /** Delete suppliers. */
    public const SUPPLIERS_DELETE = 'suppliers.delete';

    /** Read purchases. */
    public const PURCHASES_VIEW = 'purchases.view';

    /** Create purchases (which also post the stock-in movements). */
    public const PURCHASES_CREATE = 'purchases.create';

    /** Edit purchases. */
    public const PURCHASES_UPDATE = 'purchases.update';

    /** Delete purchases. */
    public const PURCHASES_DELETE = 'purchases.delete';

    /** Read sales. */
    public const SALES_VIEW = 'sales.view';

    /** Create sales (which also deducts ledger stock). */
    public const SALES_CREATE = 'sales.create';

    /** Edit sales. */
    public const SALES_UPDATE = 'sales.update';

    /** Delete sales. */
    public const SALES_DELETE = 'sales.delete';

    /**
     * Record a payment against an existing sale.
     *
     * Its own capability because taking money is a distinct capability from
     * raising the sale itself: a cashier who may take payment need not be
     * allowed to create or amend sales.
     */
    public const SALES_PAYMENTS_CREATE = 'sales.payments.create';

    /** Read sale returns. */
    public const SALE_RETURNS_VIEW = 'sale_returns.view';

    /** Create sale returns (which also restore ledger stock). */
    public const SALE_RETURNS_CREATE = 'sale_returns.create';

    /** Read expenses. */
    public const EXPENSES_VIEW = 'expenses.view';

    /** Create expenses. */
    public const EXPENSES_CREATE = 'expenses.create';

    /** Edit expenses. */
    public const EXPENSES_UPDATE = 'expenses.update';

    /** Delete expenses. */
    public const EXPENSES_DELETE = 'expenses.delete';

    /** Read expense categories. */
    public const EXPENSE_CATEGORIES_VIEW = 'expense_categories.view';

    /** Create expense categories. */
    public const EXPENSE_CATEGORIES_CREATE = 'expense_categories.create';

    /** Edit expense categories. */
    public const EXPENSE_CATEGORIES_UPDATE = 'expense_categories.update';

    /** Delete expense categories. */
    public const EXPENSE_CATEGORIES_DELETE = 'expense_categories.delete';

    /**
     * Read a cash register: the current/shift view, its summary and its
     * transaction log. A cashier needs this to run a till.
     */
    public const CASH_REGISTERS_VIEW = 'cash_registers.view';

    /** Open a cash register at the start of a shift. */
    public const CASH_REGISTERS_CREATE = 'cash_registers.create';

    /** Close a cash register at the end of a shift. */
    public const CASH_REGISTERS_UPDATE = 'cash_registers.update';

    /**
     * Record a manual cash movement (cash in / cash out).
     *
     * Moving money in or out of the drawer by hand is not a CRUD verb, so this
     * capability has no `create/update/delete` siblings. As with
     * {@see INVENTORY_MANAGE} the capability *name* is preserved from the
     * original API while the permission slug is
     * {@see CASH_REGISTERS_TRANSACTIONS_CREATE}, which names the action.
     */
    public const CASH_REGISTERS_MANAGE = 'cash_registers.manage';

    /**
     * The permission slug backing {@see CASH_REGISTERS_MANAGE}.
     *
     * Matches the nested resource it acts on: the cash drawer owns transactions
     * (`POST /cash-registers/{register}/transactions`), so writing one is
     * creating one. Consistent with `sales.payments.create` elsewhere in the
     * catalogue.
     */
    public const CASH_REGISTERS_TRANSACTIONS_CREATE = 'cash_registers.transactions.create';

    /**
     * Amend a manual cash in / cash out that was already recorded.
     *
     * A sibling of {@see CASH_REGISTERS_TRANSACTIONS_CREATE}, not a replacement
     * for it: recording a movement and correcting one are separate decisions, so
     * a role that may open the drawer need not be able to rewrite its history.
     *
     * The slug is identical to the capability name, because unlike
     * {@see CASH_REGISTERS_MANAGE} this write *is* an ordinary CRUD verb and
     * nothing about it is API surface that must be preserved.
     *
     * The rule it authorises is deliberately narrow: only rows the API
     * classifies as manual (`CashRegisterTransaction::MANUAL_TYPES` with no
     * reference) are eligible. A sale- or expense-generated movement is owned by
     * the flow that wrote it and is refused here regardless of the grant.
     */
    public const CASH_REGISTERS_TRANSACTIONS_UPDATE = 'cash_registers.transactions.update';

    /**
     * Remove a manual cash in / cash out that was already recorded.
     *
     * The narrowest capability in the catalogue for the same reason as
     * {@see CASH_REGISTERS_TRANSACTIONS_UPDATE}: it removes a row from the
     * drawer's ledger, and only a manual one. Automatic movements are never
     * deletable through it — deleting a cash sale would silently unbalance the
     * sale and the drawer against each other.
     *
     * Identical to its capability name; no alias is involved.
     */
    public const CASH_REGISTERS_TRANSACTIONS_DELETE = 'cash_registers.transactions.delete';

    /**
     * Read the reporting suite (dashboard, sales, purchases, expenses,
     * profit-loss and inventory).
     *
     * Reports are read-only, so a single capability covers the whole domain.
     * The slug is the pre-existing `reports.view` permission.
     */
    public const REPORTS_VIEW = 'reports.view';

    /**
     * The permission slug each capability resolves through.
     *
     * A **capability** is the name the API authorises against — the `can:`
     * middleware, `$user->hasPermission()`, and the `capabilities` list
     * `UserResource` publishes. A **slug** is a row in the global `permissions`
     * catalogue. For almost every capability the two are the same string, which
     * is why this map reads as an identity listing; the only exceptions are
     * {@see INVENTORY_MANAGE} and {@see CASH_REGISTERS_MANAGE}, whose names are
     * kept for API compatibility while their slugs name the action.
     *
     * Each capability maps to exactly one slug. There are no aliases left: the
     * coarse `<resource>.manage` compatibility shim is gone, and the grants it
     * used to imply were materialised into real `permission_role` rows by
     * `2026_10_01_300000_remove_coarse_manage_permissions` before the coarse
     * catalogue rows were dropped. That keeps authorization expressed directly
     * in the data, where a tenant can see and edit it, instead of hidden behind
     * a naming convention in this file.
     *
     * The `expenses.manage -> expense_categories.*` and
     * `roles.manage -> permissions.view` / `users.roles.update` relationships
     * were real cross-domain aliases, not accidents. They were preserved by
     * materialising those grants for every role that held the coarse
     * permission, so no role lost access when the alias disappeared.
     *
     * @var array<string, array<int, string>>
     */
    public const CAPABILITIES = [
        self::ROLES_VIEW => [self::ROLES_VIEW],
        self::ROLES_CREATE => [self::ROLES_CREATE],
        self::ROLES_UPDATE => [self::ROLES_UPDATE],
        self::ROLES_DELETE => [self::ROLES_DELETE],
        self::PERMISSIONS_VIEW => [self::PERMISSIONS_VIEW],
        self::USERS_VIEW => [self::USERS_VIEW],
        self::USERS_CREATE => [self::USERS_CREATE],
        self::USERS_UPDATE => [self::USERS_UPDATE],
        self::USERS_ROLES_UPDATE => [self::USERS_ROLES_UPDATE],
        self::SETTINGS_VIEW => [self::SETTINGS_VIEW],
        self::SETTINGS_UPDATE => [self::SETTINGS_UPDATE],

        self::CATEGORIES_VIEW => [self::CATEGORIES_VIEW],
        self::CATEGORIES_CREATE => [self::CATEGORIES_CREATE],
        self::CATEGORIES_UPDATE => [self::CATEGORIES_UPDATE],
        self::CATEGORIES_DELETE => [self::CATEGORIES_DELETE],

        self::PRODUCTS_VIEW => [self::PRODUCTS_VIEW],
        self::PRODUCTS_CREATE => [self::PRODUCTS_CREATE],
        self::PRODUCTS_UPDATE => [self::PRODUCTS_UPDATE],
        self::PRODUCTS_DELETE => [self::PRODUCTS_DELETE],

        self::INVENTORY_VIEW => [self::INVENTORY_VIEW],
        self::INVENTORY_MANAGE => [self::INVENTORY_ADJUST],

        self::CUSTOMERS_VIEW => [self::CUSTOMERS_VIEW],
        self::CUSTOMERS_CREATE => [self::CUSTOMERS_CREATE],
        self::CUSTOMERS_UPDATE => [self::CUSTOMERS_UPDATE],
        self::CUSTOMERS_DELETE => [self::CUSTOMERS_DELETE],

        self::SUPPLIERS_VIEW => [self::SUPPLIERS_VIEW],
        self::SUPPLIERS_CREATE => [self::SUPPLIERS_CREATE],
        self::SUPPLIERS_UPDATE => [self::SUPPLIERS_UPDATE],
        self::SUPPLIERS_DELETE => [self::SUPPLIERS_DELETE],

        self::PURCHASES_VIEW => [self::PURCHASES_VIEW],
        self::PURCHASES_CREATE => [self::PURCHASES_CREATE],
        self::PURCHASES_UPDATE => [self::PURCHASES_UPDATE],
        self::PURCHASES_DELETE => [self::PURCHASES_DELETE],

        self::SALES_VIEW => [self::SALES_VIEW],
        self::SALES_CREATE => [self::SALES_CREATE],
        self::SALES_UPDATE => [self::SALES_UPDATE],
        self::SALES_DELETE => [self::SALES_DELETE],
        self::SALES_PAYMENTS_CREATE => [self::SALES_PAYMENTS_CREATE],

        self::SALE_RETURNS_VIEW => [self::SALE_RETURNS_VIEW],
        self::SALE_RETURNS_CREATE => [self::SALE_RETURNS_CREATE],

        self::EXPENSES_VIEW => [self::EXPENSES_VIEW],
        self::EXPENSES_CREATE => [self::EXPENSES_CREATE],
        self::EXPENSES_UPDATE => [self::EXPENSES_UPDATE],
        self::EXPENSES_DELETE => [self::EXPENSES_DELETE],

        self::EXPENSE_CATEGORIES_VIEW => [self::EXPENSE_CATEGORIES_VIEW],
        self::EXPENSE_CATEGORIES_CREATE => [self::EXPENSE_CATEGORIES_CREATE],
        self::EXPENSE_CATEGORIES_UPDATE => [self::EXPENSE_CATEGORIES_UPDATE],
        self::EXPENSE_CATEGORIES_DELETE => [self::EXPENSE_CATEGORIES_DELETE],

        self::CASH_REGISTERS_VIEW => [self::CASH_REGISTERS_VIEW],
        self::CASH_REGISTERS_CREATE => [self::CASH_REGISTERS_CREATE],
        self::CASH_REGISTERS_UPDATE => [self::CASH_REGISTERS_UPDATE],
        self::CASH_REGISTERS_MANAGE => [self::CASH_REGISTERS_TRANSACTIONS_CREATE],
        self::CASH_REGISTERS_TRANSACTIONS_UPDATE => [self::CASH_REGISTERS_TRANSACTIONS_UPDATE],
        self::CASH_REGISTERS_TRANSACTIONS_DELETE => [self::CASH_REGISTERS_TRANSACTIONS_DELETE],

        self::REPORTS_VIEW => [self::REPORTS_VIEW],
    ];

    /**
     * Every permission slug the catalogue is expected to contain.
     *
     * The canonical slug of every capability, de-duplicated and in
     * {@see ALL_CAPABILITIES} order. This is the set the seeder creates and the
     * set the Step 6 migration treats as "the fine-grained taxonomy", so it is
     * derived rather than restated: adding a capability to
     * {@see CAPABILITIES} is enough to have its slug seeded.
     *
     * @return array<int, string>
     */
    public static function catalogueSlugs(): array
    {
        $slugs = [];

        foreach (self::ALL_CAPABILITIES as $capability) {
            foreach (self::slugsFor($capability) as $slug) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * The canonical slug backing a capability.
     *
     * The inverse of {@see self::CAPABILITIES}, and what test fixtures and the
     * seeder need when they want "the permission for this capability". Differs
     * from the capability name only for {@see INVENTORY_MANAGE} and
     * {@see CASH_REGISTERS_MANAGE}.
     */
    public static function slugFor(string $capability): string
    {
        return self::slugsFor($capability)[0] ?? $capability;
    }

    /** Every capability name, in a stable order (used for docs and tests). */
    public const ALL_CAPABILITIES = [
        self::ROLES_VIEW,
        self::ROLES_CREATE,
        self::ROLES_UPDATE,
        self::ROLES_DELETE,
        self::PERMISSIONS_VIEW,
        self::USERS_VIEW,
        self::USERS_CREATE,
        self::USERS_UPDATE,
        self::USERS_ROLES_UPDATE,
        self::SETTINGS_VIEW,
        self::SETTINGS_UPDATE,
        self::CATEGORIES_VIEW,
        self::CATEGORIES_CREATE,
        self::CATEGORIES_UPDATE,
        self::CATEGORIES_DELETE,
        self::PRODUCTS_VIEW,
        self::PRODUCTS_CREATE,
        self::PRODUCTS_UPDATE,
        self::PRODUCTS_DELETE,
        self::INVENTORY_VIEW,
        self::INVENTORY_MANAGE,
        self::CUSTOMERS_VIEW,
        self::CUSTOMERS_CREATE,
        self::CUSTOMERS_UPDATE,
        self::CUSTOMERS_DELETE,
        self::SUPPLIERS_VIEW,
        self::SUPPLIERS_CREATE,
        self::SUPPLIERS_UPDATE,
        self::SUPPLIERS_DELETE,
        self::PURCHASES_VIEW,
        self::PURCHASES_CREATE,
        self::PURCHASES_UPDATE,
        self::PURCHASES_DELETE,
        self::SALES_VIEW,
        self::SALES_CREATE,
        self::SALES_UPDATE,
        self::SALES_DELETE,
        self::SALES_PAYMENTS_CREATE,
        self::SALE_RETURNS_VIEW,
        self::SALE_RETURNS_CREATE,
        self::EXPENSES_VIEW,
        self::EXPENSES_CREATE,
        self::EXPENSES_UPDATE,
        self::EXPENSES_DELETE,
        self::EXPENSE_CATEGORIES_VIEW,
        self::EXPENSE_CATEGORIES_CREATE,
        self::EXPENSE_CATEGORIES_UPDATE,
        self::EXPENSE_CATEGORIES_DELETE,
        self::CASH_REGISTERS_VIEW,
        self::CASH_REGISTERS_CREATE,
        self::CASH_REGISTERS_UPDATE,
        self::CASH_REGISTERS_MANAGE,
        self::CASH_REGISTERS_TRANSACTIONS_UPDATE,
        self::CASH_REGISTERS_TRANSACTIONS_DELETE,
        self::REPORTS_VIEW,
    ];

    /** Columns a client may sort the catalogue by. */
    public const SORTABLE_COLUMNS = ['name', 'slug', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * The permission slug(s) that satisfy the given capability.
     *
     * Returns exactly one slug for every known capability — the canonical,
     * fine-grained one. An unknown capability falls back to itself, so a typo in
     * a `can:` route name fails closed instead of throwing.
     *
     * @return array<int, string>
     */
    public static function slugsFor(string $capability): array
    {
        return self::CAPABILITIES[$capability] ?? [$capability];
    }

    /**
     * Is the capability a known one?
     *
     * Guards against a typo silently creating an un-grantable permission.
     */
    public static function isKnown(string $capability): bool
    {
        return array_key_exists($capability, self::CAPABILITIES);
    }

    /**
     * Does the user satisfy the capability?
     *
     * Used by the Gate definition in `AppServiceProvider`, which is in turn used
     * by route middleware, `$user->can()` and `$user->hasPermission()`.
     *
     * Effective access is the user's role grants, plus their ALLOW overrides,
     * minus their DENY overrides:
     *
     *     Effective = (role permissions ∪ user ALLOW) − user DENY
     *
     * A DENY outranks everything: if the slug backing this capability
     * (`slugsFor()`) is explicitly denied, the capability is refused even when a
     * role — or a user ALLOW — would otherwise have granted it. That makes a
     * per-user revocation authoritative.
     *
     * The DENY check runs against the slug list rather than a single value
     * because `slugsFor()` is a list by contract; with one canonical slug per
     * capability it degenerates to exactly that one comparison.
     */
    public function grants(User $user, string $capability): bool
    {
        $slugs = self::slugsFor($capability);

        // An explicit DENY wins over role grants and ALLOWs.
        $denied = $user->deniedPermissionSlugs();

        foreach ($slugs as $slug) {
            if (in_array($slug, $denied, true)) {
                return false;
            }
        }

        $effective = $user->effectivePermissionSlugs();

        foreach ($slugs as $slug) {
            if (in_array($slug, $effective, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every capability the user satisfies, as capability names.
     *
     * Used by `UserResource` to tell the client what it may reach, so the UI can
     * hide a module before a request is ever made instead of reacting to the
     * `403` afterwards.
     *
     * Capability *names* are returned — not the raw permission slugs held through
     * the user's roles — so the client compares one value against another
     * (`users.view`) and never has to re-implement the capability-to-slug
     * mapping of {@see self::CAPABILITIES} (`inventory.adjust` satisfying
     * `inventory.manage`). That mapping therefore stays in exactly one place and
     * cannot drift between the server's Gate and the client's own idea of the
     * rules.
     *
     * The user's role permissions and their overrides are both memoised on the
     * model instance, so the whole list costs at most two queries however many
     * capabilities are tested.
     *
     * @return array<int, string>
     */
    public function resolvedCapabilities(User $user): array
    {
        $granted = [];

        foreach (self::ALL_CAPABILITIES as $capability) {
            if ($this->grants($user, $capability)) {
                $granted[] = $capability;
            }
        }

        return $granted;
    }

    /**
     * Paginated permission catalogue with search.
     *
     * The catalogue is global, so no tenant scoping applies here.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Permission>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Permission::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->search($search);
        }

        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'desc'
            ? 'desc'
            : 'asc';

        $perPage = max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage);
    }

    /**
     * Resolve existing permission ids in a single query.
     *
     * Permissions are global, so only existence has to be checked â€” there is no
     * ownership to verify. Returns the ids that actually exist, letting the
     * caller compare against the requested set and reject anything unknown
     * before the pivot table is touched.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Replace a role's permission grants.
     *
     * `sync()` is deliberate: `permission_role` carries a unique
     * `(permission_id, role_id)` index, so `sync()` can never create duplicate
     * pivot rows, and it detaches grants that are no longer requested.
     *
     * @param  array<int, int>  $ids
     */
    public function syncToRole(Role $role, array $ids): void
    {
        $role->permissions()->sync($ids);
    }
}
