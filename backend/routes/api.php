<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use Illuminate\Support\Facades\Route;

/**
 * ---------------------------------------------------------------------------
 * API routes — every endpoint lives under /api/v1/.
 *
 * The `api` middleware group (configured in bootstrap/app.php) provides API
 * throttling (`throttle:api`) and route model binding.
 *
 * Versioning lives in the URL prefix so a future major bump simply adds a `v2`
 * group without disturbing `v1`. Controllers always live in
 * `App\Http\Controllers\Api\V{n}`.
 *
 * Each domain gets its own prefix + name group inside `v1` (auth, health,
 * categories, products, inventory today; sales, purchases, ... in later
 * phases).
 *
 * Protected domains are wrapped in `Route::middleware('auth:sanctum')` plus
 * the `active` alias (`App\Http\Middleware\EnsureUserIsActive`), which revokes
 * the session of an account that has been suspended since it logged in.
 * Phase 20 resource routes deliberately do NOT type-hint Eloquent models for
 * `{category}` / `{product}`: controllers resolve those ids through the
 * business-scoped service queries, so another tenant's record is a plain 404
 * and implicit route model binding can never bypass tenancy.
 * ---------------------------------------------------------------------------
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public health probe — no authentication, no database access.
    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('auth')->name('auth.')->group(function () {
        // Public authentication route. `throttle:login` is a dedicated,
        // much tighter limiter than the `api` group default (see
        // AppServiceProvider) and bounds credential guessing per account+IP.
        // It runs ahead of the form request, so even a payload that fails
        // validation still consumes an attempt and cannot be used to probe the
        // endpoint for free.
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        // Protected routes — require a valid Sanctum API token and an account
        // that is still active.
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
        });
    });

    // -------------------------------------------------------------------------
    // Phase 20 — products, categories and inventory (ledger) endpoints.
    // Phase 21 — customers, suppliers and purchases; purchase stock-ins flow
    // through the same ledger. Phase 23 — expenses, cash drawer, reports.
    //
    // Every route requires a valid Sanctum token, an active account and the
    // business capability for the action, in that order:
    //
    //   1. `auth:sanctum` -> 401 for a missing/invalid token.
    //   2. `active`       -> 401 for a suspended account, revoking its token.
    //   3. `can:<cap>`    -> 403 for an authenticated user without the
    //      permission. Abilities are Gates defined once in
    //      `AppServiceProvider::configurePermissionGates()` and resolved by
    //      `PermissionService`, so route middleware, controllers and
    //      `$user->hasPermission()` can never disagree.
    //
    // The resources are declared as explicit routes rather than
    // `Route::apiResource()` precisely because each verb needs its *own*
    // capability: `apiResource()` applies one middleware stack to all five
    // verbs, which would either over-grant writes to readers or under-grant
    // them to editors.
    //
    // Tenancy is handled one level down, inside the services: every role,
    // target user and setting is resolved with the authenticated user's
    // `business_id`, so another tenant's record is a plain 404. Route
    // parameters are therefore raw ids — implicit route model binding must
    // never be able to hand a foreign model to a controller.
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Categories — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::get('/categories', [CategoryController::class, 'index'])
            ->middleware('can:categories.view')
            ->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])
            ->middleware('can:categories.create')
            ->name('categories.store');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])
            ->middleware('can:categories.view')
            ->name('categories.show');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])
            ->middleware('can:categories.update')
            ->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('can:categories.delete')
            ->name('categories.destroy');

        // Products — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('can:products.view')
            ->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('can:products.create')
            ->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])
            ->middleware('can:products.view')
            ->name('products.show');
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])
            ->middleware('can:products.update')
            ->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])
            ->middleware('can:products.delete')
            ->name('products.destroy');

        // Customers — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('can:customers.view')
            ->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('can:customers.create')
            ->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('can:customers.view')
            ->name('customers.show');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('can:customers.update')
            ->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('can:customers.delete')
            ->name('customers.destroy');

        // Suppliers — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::get('/suppliers', [SupplierController::class, 'index'])
            ->middleware('can:suppliers.view')
            ->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])
            ->middleware('can:suppliers.create')
            ->name('suppliers.store');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])
            ->middleware('can:suppliers.view')
            ->name('suppliers.show');
        Route::match(['put', 'patch'], '/suppliers/{supplier}', [SupplierController::class, 'update'])
            ->middleware('can:suppliers.update')
            ->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('can:suppliers.delete')
            ->name('suppliers.destroy');

        // Purchases — full CRUD; creation posts stock-in movements to the
        // ledger inside one transaction, and updates/deletes of
        // inventory-affecting purchases are refused by the service layer.
        Route::get('/purchases', [PurchaseController::class, 'index'])
            ->middleware('can:purchases.view')
            ->name('purchases.index');
        Route::post('/purchases', [PurchaseController::class, 'store'])
            ->middleware('can:purchases.create')
            ->name('purchases.store');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])
            ->middleware('can:purchases.view')
            ->name('purchases.show');
        Route::match(['put', 'patch'], '/purchases/{purchase}', [PurchaseController::class, 'update'])
            ->middleware('can:purchases.update')
            ->name('purchases.update');
        Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])
            ->middleware('can:purchases.delete')
            ->name('purchases.destroy');

        // Sales — full CRUD; creation deducts ledger stock in one transaction.
        Route::get('/sales', [SaleController::class, 'index'])
            ->middleware('can:sales.view')
            ->name('sales.index');
        Route::post('/sales', [SaleController::class, 'store'])
            ->middleware('can:sales.create')
            ->name('sales.store');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])
            ->middleware('can:sales.view')
            ->name('sales.show');
        Route::match(['put', 'patch'], '/sales/{sale}', [SaleController::class, 'update'])
            ->middleware('can:sales.update')
            ->name('sales.update');
        Route::delete('/sales/{sale}', [SaleController::class, 'destroy'])
            ->middleware('can:sales.delete')
            ->name('sales.destroy');

        // Recording a payment against a sale is its own capability: taking
        // money is not the same as being allowed to raise or amend the sale.
        Route::post('/sales/{sale}/payments', [SaleController::class, 'pay'])
            ->middleware('can:sales.payments.create')
            ->name('sales.payments.store');

        // Sale returns — list/create/show; stock restored via ledger.
        Route::get('/sale-returns', [SaleReturnController::class, 'index'])
            ->middleware('can:sale_returns.view')
            ->name('sale-returns.index');
        Route::post('/sale-returns', [SaleReturnController::class, 'store'])
            ->middleware('can:sale_returns.create')
            ->name('sale-returns.store');
        Route::get('/sale-returns/{sale_return}', [SaleReturnController::class, 'show'])
            ->middleware('can:sale_returns.view')
            ->name('sale-returns.show');

        // Phase 23 — expenses, cash drawer, read-only reports and dashboard.
        // Route ids are raw values; services resolve them inside the authenticated
        // business so cross-tenant records are indistinguishable from missing ones.
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])
            ->middleware('can:expense_categories.view')
            ->name('expense-categories.index');
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])
            ->middleware('can:expense_categories.create')
            ->name('expense-categories.store');
        Route::get('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'show'])
            ->middleware('can:expense_categories.view')
            ->name('expense-categories.show');
        Route::match(['put', 'patch'], '/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'update'])
            ->middleware('can:expense_categories.update')
            ->name('expense-categories.update');
        Route::delete('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'destroy'])
            ->middleware('can:expense_categories.delete')
            ->name('expense-categories.destroy');

        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('can:expenses.view')
            ->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])
            ->middleware('can:expenses.create')
            ->name('expenses.store');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
            ->middleware('can:expenses.view')
            ->name('expenses.show');
        Route::match(['put', 'patch'], '/expenses/{expense}', [ExpenseController::class, 'update'])
            ->middleware('can:expenses.update')
            ->name('expenses.update');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
            ->middleware('can:expenses.delete')
            ->name('expenses.destroy');

        // Cash drawer. Reading the shift, opening it and closing it are three
        // separate capabilities, and a manual cash movement (cash in / cash
        // out) is a fourth — handled by `cash_registers.manage`.
        Route::post('/cash-registers/open', [CashRegisterController::class, 'open'])
            ->middleware('can:cash_registers.create')
            ->name('cash-registers.open');
        Route::get('/cash-registers/current', [CashRegisterController::class, 'current'])
            ->middleware('can:cash_registers.view')
            ->name('cash-registers.current');
        Route::get('/cash-registers/{cashRegister}/transactions', [CashRegisterController::class, 'transactions'])
            ->middleware('can:cash_registers.view')
            ->name('cash-registers.transactions.index');
        Route::post('/cash-registers/{cashRegister}/transactions', [CashRegisterController::class, 'storeTransaction'])
            ->middleware('can:cash_registers.manage')
            ->name('cash-registers.transactions.store');
        Route::get('/cash-registers/{cashRegister}/summary', [CashRegisterController::class, 'summary'])
            ->middleware('can:cash_registers.view')
            ->name('cash-registers.summary');
        Route::get('/cash-registers/{cashRegister}', [CashRegisterController::class, 'show'])
            ->middleware('can:cash_registers.view')
            ->name('cash-registers.show');
        Route::post('/cash-registers/{cashRegister}/close', [CashRegisterController::class, 'close'])
            ->middleware('can:cash_registers.update')
            ->name('cash-registers.close');

        // Reports are read-only, so the whole suite shares one capability.
        Route::get('/reports/sales', [ReportController::class, 'sales'])
            ->middleware('can:reports.view')
            ->name('reports.sales');
        Route::get('/reports/purchases', [ReportController::class, 'purchases'])
            ->middleware('can:reports.view')
            ->name('reports.purchases');
        Route::get('/reports/expenses', [ReportController::class, 'expenses'])
            ->middleware('can:reports.view')
            ->name('reports.expenses');
        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])
            ->middleware('can:reports.view')
            ->name('reports.profit-loss');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])
            ->middleware('can:reports.view')
            ->name('reports.inventory');
        Route::get('/reports/dashboard', [ReportController::class, 'dashboard'])
            ->middleware('can:reports.view')
            ->name('reports.dashboard');

        // Inventory — movement log + manual stock adjustment. Stock is only
        // ever written through the ledger, never through a product field.
        // The log is a read; the adjustment is the `inventory.manage` write.
        Route::get('/stock-movements', [StockMovementController::class, 'index'])
            ->middleware('can:inventory.view')
            ->name('stock-movements.index');
        Route::post('/products/{product}/stock-adjustments', [StockMovementController::class, 'adjust'])
            ->middleware('can:inventory.manage')
            ->name('products.stock-adjustments');
    });

    // -------------------------------------------------------------------------
    // Phase 24B — roles, permissions, user-role assignment and business
    // settings.
    //
    // Two layers of protection, in this order:
    //   1. `auth:sanctum`  -> 401 for a missing/invalid token.
    //   2. `active`        -> 401 for a suspended account, revoking its token.
    //   3. `can:<ability>` -> 403 for an authenticated user without the
    //      permission. The abilities are Gates defined once in
    //      `AppServiceProvider::configurePermissionGates()` and resolved by
    //      `PermissionService`, so route middleware, controllers and
    //      `$user->hasPermission()` can never disagree.
    //
    // Tenancy is handled one level down, inside the services: every role,
    // target user and setting is resolved with the authenticated user's
    // `business_id`, so another tenant's record is a plain 404. Route
    // parameters are therefore raw ids — implicit route model binding must
    // never be able to hand a foreign model to a controller.
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Roles — full CRUD, scoped to the caller's business.
        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('can:roles.view')
            ->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('can:roles.create')
            ->name('roles.store');
        Route::get('/roles/{role}', [RoleController::class, 'show'])
            ->middleware('can:roles.view')
            ->name('roles.show');
        Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update'])
            ->middleware('can:roles.update')
            ->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('can:roles.delete')
            ->name('roles.destroy');

        // Permissions — read-only. The catalogue is global (no `business_id`),
        // so there is no tenant scoping and no create/update/delete.
        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware('can:permissions.view')
            ->name('permissions.index');

        // Staff directory — read and manage the accounts of this business.
        Route::get('/users', [UserController::class, 'index'])
            ->middleware('can:users.view')
            ->name('users.index');
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('can:users.create')
            ->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])
            ->middleware('can:users.view')
            ->name('users.show');
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
            ->middleware('can:users.update')
            ->name('users.update');

        // User -> role assignment (full replace, idempotent).
        Route::match(['put', 'patch'], '/users/{user}/roles', [UserRoleController::class, 'sync'])
            ->middleware('can:users.roles.update')
            ->name('users.roles.update');

        // Business settings — always the caller's own business; there is no
        // {business} segment to tamper with.
        Route::get('/settings', [SettingsController::class, 'show'])
            ->middleware('can:settings.view')
            ->name('settings.show');
        Route::match(['put', 'patch'], '/settings', [SettingsController::class, 'update'])
            ->middleware('can:settings.update')
            ->name('settings.update');
    });
});
