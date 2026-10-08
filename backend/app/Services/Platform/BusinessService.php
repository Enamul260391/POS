<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InvoiceSequence;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-level business (tenant) management.
 *
 * This is the platform counterpart to the tenant domain services
 * (`CategoryService`, `SupplierService`, ...): thin callers stay in the
 * controller, validation in Form Requests, and every business rule here.
 *
 * Platform scope, not tenant scope: methods take no `business_id` from the
 * caller and resolve businesses globally (soft-deleted rows excluded, so a
 * deleted business is a plain 404). Callers are the
 * `/api/v1/platform/businesses` endpoints, which sit behind
 * `auth:sanctum` + `active` + `platform` and the individual
 * `platform.businesses.*` Gates.
 *
 * The service never touches users, roles or the Platform Owner identity:
 * creating a business inserts exactly one `businesses` row, so the owner
 * keeps `business_id = NULL` and the new tenant starts empty.
 */
final class BusinessService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = ['name', 'email', 'status', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** Columns a client may change through update. `id`/`status` never among them. */
    private const EDITABLE_COLUMNS = [
        'name', 'phone', 'email', 'address', 'logo', 'currency', 'timezone',
    ];

    /**
     * Paginated platform-wide business list with search and status filtering.
     *
     * `users_count` is added by a correlated sub-query so the list stays a
     * single round trip and never triggers an N+1.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Business>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one business by id, or fail with a 404 envelope.
     *
     * Soft-deleted rows are excluded by the default scope, so a deleted
     * business reads as "not found".
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId): Business
    {
        return $this->query()->whereKey($businessId)->firstOrFail();
    }

    /**
     * Create a business as a separate, empty tenant with default roles.
     *
     * Exactly one `businesses` row is inserted, and the four seeded roles
     * (admin, manager, salesman, accountant) are created with their permission
     * grants. The write runs inside a transaction so a halfway failure
     * cannot leave a half-written tenant behind.
     *
     * @param  array<string, mixed>  $data  Validated platform payload.
     */
    public function create(array $data): Business
    {
        $business = DB::transaction(function () use ($data): Business {
            $business = Business::create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'logo' => $data['logo'] ?? null,
                'currency' => $data['currency'] ?? 'BDT',
                'timezone' => $data['timezone'] ?? 'Asia/Dhaka',
                'status' => $data['status'] ?? Business::STATUS_ACTIVE,
            ]);

            $this->createDefaultRoles($business);

            return $business;
        });

        return $this->find($business->getKey());
    }

    /**
     * Create the four default roles for a new business with their permission grants.
     *
     * Mirrors the RolesAndPermissionsSeeder logic but runs at business creation time
     * so that newly created businesses immediately have the admin role available
     * for first-admin provisioning.
     */
    private function createDefaultRoles(Business $business): void
    {
        $roles = [
            'admin' => 'Full access to all features',
            'manager' => 'Manage products, sales, purchases, and reports',
            'salesman' => 'Handle sales and customer management',
            'accountant' => 'Manage purchases, expenses, and payments',
        ];

        $roleIds = [];
        foreach ($roles as $slug => $description) {
            $role = Role::create([
                'business_id' => $business->getKey(),
                'name' => ucfirst($slug),
                'slug' => $slug,
                'description' => $description,
            ]);
            $roleIds[$slug] = $role->getKey();
        }

        // Ensure all permissions from the catalogue exist (mirrors RolesAndPermissionsSeeder)
        $this->ensurePermissionsExist();

        $permissionIds = Permission::query()->pluck('id')->all();

        // Admin gets all permissions
        if ($permissionIds !== [] && isset($roleIds['admin'])) {
            $now = now();
            DB::table('permission_role')->insert(array_map(
                static fn (int $permissionId): array => [
                    'permission_id' => $permissionId,
                    'role_id' => $roleIds['admin'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $permissionIds,
            ));
        }

        // Other roles get their specific grants (from RolesAndPermissionsSeeder::roleGrants())
        $grants = [
            'manager' => [
                'products.view', 'products.create', 'products.update', 'products.delete',
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                'sales.view', 'sales.create', 'sales.update', 'sales.delete',
                'sales.payments.create',
                'sale_returns.view', 'sale_returns.create',
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
                'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'expense_categories.view', 'expense_categories.create',
                'expense_categories.update', 'expense_categories.delete',
                'inventory.view', 'inventory.adjust',
                'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
                'cash_registers.transactions.create',
                'cash_registers.transactions.update',
                'cash_registers.transactions.delete',
                'reports.view',
            ],
            'salesman' => [
                'products.view', 'products.create', 'products.update', 'products.delete',
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                'sales.view', 'sales.create', 'sales.update', 'sales.delete',
                'sales.payments.create',
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
                'cash_registers.transactions.create',
                'cash_registers.transactions.update',
                'cash_registers.transactions.delete',
            ],
            'accountant' => [
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
                'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'expense_categories.view', 'expense_categories.create',
                'expense_categories.update', 'expense_categories.delete',
                'sales.payments.create',
                'reports.view',
            ],
        ];

        foreach ($grants as $slug => $permissionSlugs) {
            if (!isset($roleIds[$slug])) {
                continue;
            }

            $permissionIdList = Permission::query()
                ->whereIn('slug', $permissionSlugs)
                ->pluck('id')
                ->all();

            if ($permissionIdList !== []) {
                $now = now();
                DB::table('permission_role')->insert(array_map(
                    static fn (int $permissionId): array => [
                        'permission_id' => $permissionId,
                        'role_id' => $roleIds[$slug],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $permissionIdList,
                ));
            }
        }
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
     * Update the editable master data of a business (PUT and PATCH).
     *
     * Only EDITABLE_COLUMNS are written, so `id` and `status` cannot change
     * here even if they are sent.
     *
     * @param  array<string, mixed>  $data  Validated platform payload.
     */
    public function update(Business $business, array $data): Business
    {
        $attributes = Arr::only($data, self::EDITABLE_COLUMNS);

        if ($attributes !== []) {
            $business->fill($attributes)->save();
        }

        return $this->find($business->getKey());
    }

    /**
     * Activate or deactivate a business using the existing status model.
     *
     * Only the `status` column is written; users are never deleted or moved.
     */
    public function setStatus(Business $business, string $status): Business
    {
        $business->fill(['status' => $status])->save();

        return $this->find($business->getKey());
    }

    /**
     * Soft-delete a business that holds no tenant data.
     *
     * Deletion is the Eloquent soft delete the model already carries
     * (`deleted_at` only): no tenant row is destroyed, so history stays
     * intact. A business that still owns users, roles, catalogue, documents,
     * ledger rows or settings is refused with a 409 business error instead of
     * a destructive cascade — the schema's `cascadeOnDelete` foreign keys must
     * never be used to wipe a live tenant, and `roles.business_id` is
     * `restrictOnDelete` precisely to stop that.
     *
     * @throws BusinessRuleException when the business still owns tenant data.
     */
    public function delete(Business $business): void
    {
        DB::transaction(function () use ($business): void {
            $fresh = $this->find($business->getKey());

            if ($this->hasTenantData($fresh)) {
                throw new BusinessRuleException('Cannot delete business because it has related records.');
            }

            $fresh->delete();
        });
    }

    /**
     * Does any tenant-owned row still belong to this business?
     *
     * Every child table carrying a `business_id` foreign key is checked, so a
     * delete can never silently orphan or cascade away live tenant data.
     * Soft-deleting children count as references as well: their history must
     * stay readable, mirroring the tenant services' own delete guards.
     */
    public function hasTenantData(Business $business): bool
    {
        $businessId = $business->getKey();

        foreach ([User::class, Role::class, UserPermission::class] as $model) {
            if ($model::query()->where('business_id', $businessId)->exists()) {
                return true;
            }
        }

        foreach ([Category::class, Product::class, Customer::class, Supplier::class, ExpenseCategory::class] as $model) {
            if ($model::withTrashed()->where('business_id', $businessId)->exists()) {
                return true;
            }
        }

        foreach ([
            Purchase::class, Sale::class, SaleReturn::class, Payment::class,
            StockMovement::class, Expense::class, CashRegister::class,
            CashRegisterTransaction::class, BusinessSetting::class,
            InvoiceSequence::class, CustomerLedgerEntry::class,
        ] as $model) {
            if ($model::query()->where('business_id', $businessId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base query: every tenant, with the user count pre-selected.
     *
     * @return Builder<Business>
     */
    private function query(): Builder
    {
        return Business::query()->withCount('users');
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Business>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Business>
     */
    private function applyOrdering(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'desc'
            ? 'desc'
            : 'asc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    /**
     * Page size, clamped to the documented maximum.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
