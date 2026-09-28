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
 * (`roles.update`, `sales.create`, `products.view`, `inventory.manage`, ...).
 * The existing seeded catalogue uses the coarser `<resource>.manage` convention
 * (`roles.manage`, `sales.manage`, `inventory.manage`, ...), and those rows
 * already exist in installations. Rather than inventing a second naming system
 * or forcing a reseed, each capability accepts a set of equivalent slugs: the
 * specific one **or** the coarser `*.manage` permission for the same resource.
 * A business that seeds the finer catalogue gets exact per-action control; one
 * that still has the original `*.manage` rows keeps working unchanged.
 *
 * Every check runs against the slugs held through the user's roles and is
 * memoised on the user instance, so N checks cost at most one query.
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
    // a fine-grained capability per verb, with the pre-existing coarse
    // `<resource>.manage` permission accepted as a compatibility alias. A role
    // that was seeded with only `products.manage` therefore keeps exactly the
    // product access it always had, while a role that is granted the newer
    // `products.view` can be restricted to reading.
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
     * ordinary CRUD verb. The slug is the pre-existing `inventory.manage`
     * permission, so no new permission row is introduced for it.
     */
    public const INVENTORY_MANAGE = 'inventory.manage';

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
     * The slug is the pre-existing `cash_registers.manage` permission: moving
     * money in or out of the drawer by hand is exactly what that coarse
     * capability was created for, so no new permission row is introduced.
     */
    public const CASH_REGISTERS_MANAGE = 'cash_registers.manage';

    /**
     * Read the reporting suite (dashboard, sales, purchases, expenses,
     * profit-loss and inventory).
     *
     * Reports are read-only, so a single capability covers the whole domain.
     * The slug is the pre-existing `reports.view` permission.
     */
    public const REPORTS_VIEW = 'reports.view';

    /**
     * Accepted slug aliases per capability.
     *
     * The first entry is the canonical, fine-grained slug; the rest are
     * compatibility aliases matching the pre-existing seeded convention.
     *
     * @var array<string, array<int, string>>
     */
    public const CAPABILITIES = [
        self::ROLES_VIEW => [self::ROLES_VIEW, 'roles.manage'],
        self::ROLES_CREATE => [self::ROLES_CREATE, 'roles.manage'],
        self::ROLES_UPDATE => [self::ROLES_UPDATE, 'roles.manage'],
        self::ROLES_DELETE => [self::ROLES_DELETE, 'roles.manage'],
        self::PERMISSIONS_VIEW => [self::PERMISSIONS_VIEW, 'roles.manage'],
        self::USERS_VIEW => [self::USERS_VIEW, 'users.manage'],
        self::USERS_CREATE => [self::USERS_CREATE, 'users.manage'],
        self::USERS_UPDATE => [self::USERS_UPDATE, 'users.manage'],
        self::USERS_ROLES_UPDATE => [self::USERS_ROLES_UPDATE, 'users.manage', 'roles.manage'],
        self::SETTINGS_VIEW => [self::SETTINGS_VIEW, 'settings.manage'],
        self::SETTINGS_UPDATE => [self::SETTINGS_UPDATE, 'settings.manage'],

        // Categories — `categories.manage` is new: the catalogue had no
        // category permission at all, so there is no pre-existing coarse
        // capability for this domain to alias.
        self::CATEGORIES_VIEW => [self::CATEGORIES_VIEW, 'categories.manage'],
        self::CATEGORIES_CREATE => [self::CATEGORIES_CREATE, 'categories.manage'],
        self::CATEGORIES_UPDATE => [self::CATEGORIES_UPDATE, 'categories.manage'],
        self::CATEGORIES_DELETE => [self::CATEGORIES_DELETE, 'categories.manage'],

        self::PRODUCTS_VIEW => [self::PRODUCTS_VIEW, 'products.manage'],
        self::PRODUCTS_CREATE => [self::PRODUCTS_CREATE, 'products.manage'],
        self::PRODUCTS_UPDATE => [self::PRODUCTS_UPDATE, 'products.manage'],
        self::PRODUCTS_DELETE => [self::PRODUCTS_DELETE, 'products.manage'],

        self::INVENTORY_VIEW => [self::INVENTORY_VIEW, 'inventory.manage'],
        self::INVENTORY_MANAGE => [self::INVENTORY_MANAGE, 'inventory.adjust'],

        self::CUSTOMERS_VIEW => [self::CUSTOMERS_VIEW, 'customers.manage'],
        self::CUSTOMERS_CREATE => [self::CUSTOMERS_CREATE, 'customers.manage'],
        self::CUSTOMERS_UPDATE => [self::CUSTOMERS_UPDATE, 'customers.manage'],
        self::CUSTOMERS_DELETE => [self::CUSTOMERS_DELETE, 'customers.manage'],

        self::SUPPLIERS_VIEW => [self::SUPPLIERS_VIEW, 'suppliers.manage'],
        self::SUPPLIERS_CREATE => [self::SUPPLIERS_CREATE, 'suppliers.manage'],
        self::SUPPLIERS_UPDATE => [self::SUPPLIERS_UPDATE, 'suppliers.manage'],
        self::SUPPLIERS_DELETE => [self::SUPPLIERS_DELETE, 'suppliers.manage'],

        self::PURCHASES_VIEW => [self::PURCHASES_VIEW, 'purchases.manage'],
        self::PURCHASES_CREATE => [self::PURCHASES_CREATE, 'purchases.manage'],
        self::PURCHASES_UPDATE => [self::PURCHASES_UPDATE, 'purchases.manage'],
        self::PURCHASES_DELETE => [self::PURCHASES_DELETE, 'purchases.manage'],

        self::SALES_VIEW => [self::SALES_VIEW, 'sales.manage'],
        self::SALES_CREATE => [self::SALES_CREATE, 'sales.manage'],
        self::SALES_UPDATE => [self::SALES_UPDATE, 'sales.manage'],
        self::SALES_DELETE => [self::SALES_DELETE, 'sales.manage'],
        self::SALES_PAYMENTS_CREATE => [self::SALES_PAYMENTS_CREATE, 'sales.manage'],

        // Sale returns — `sale_returns.manage` is new, for the same reason as
        // `categories.manage`: the catalogue had no permission for this domain.
        self::SALE_RETURNS_VIEW => [self::SALE_RETURNS_VIEW, 'sale_returns.manage'],
        self::SALE_RETURNS_CREATE => [self::SALE_RETURNS_CREATE, 'sale_returns.manage'],

        self::EXPENSES_VIEW => [self::EXPENSES_VIEW, 'expenses.manage'],
        self::EXPENSES_CREATE => [self::EXPENSES_CREATE, 'expenses.manage'],
        self::EXPENSES_UPDATE => [self::EXPENSES_UPDATE, 'expenses.manage'],
        self::EXPENSES_DELETE => [self::EXPENSES_DELETE, 'expenses.manage'],

        // Expense categories are a sub-resource of expenses: the existing
        // coarse `expenses.manage` is their only compatibility alias, so a role
        // that could manage expenses can always manage their categories.
        self::EXPENSE_CATEGORIES_VIEW => [self::EXPENSE_CATEGORIES_VIEW, 'expense_categories.manage', 'expenses.manage'],
        self::EXPENSE_CATEGORIES_CREATE => [self::EXPENSE_CATEGORIES_CREATE, 'expense_categories.manage', 'expenses.manage'],
        self::EXPENSE_CATEGORIES_UPDATE => [self::EXPENSE_CATEGORIES_UPDATE, 'expense_categories.manage', 'expenses.manage'],
        self::EXPENSE_CATEGORIES_DELETE => [self::EXPENSE_CATEGORIES_DELETE, 'expense_categories.manage', 'expenses.manage'],

        self::CASH_REGISTERS_VIEW => [self::CASH_REGISTERS_VIEW, 'cash_registers.manage'],
        self::CASH_REGISTERS_CREATE => [self::CASH_REGISTERS_CREATE, 'cash_registers.manage'],
        self::CASH_REGISTERS_UPDATE => [self::CASH_REGISTERS_UPDATE, 'cash_registers.manage'],
        self::CASH_REGISTERS_MANAGE => [self::CASH_REGISTERS_MANAGE, 'cash_registers.transactions.create'],

        self::REPORTS_VIEW => [self::REPORTS_VIEW, 'reports.manage'],
    ];

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
        self::REPORTS_VIEW,
    ];

    /** Columns a client may sort the catalogue by. */
    public const SORTABLE_COLUMNS = ['name', 'slug', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Slug aliases that satisfy the given capability.
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
     */
    public function grants(User $user, string $capability): bool
    {
        $held = $user->permissionSlugs();

        foreach (self::slugsFor($capability) as $slug) {
            if (in_array($slug, $held, true)) {
                return true;
            }
        }

        return false;
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
