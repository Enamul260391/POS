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
    // through the same ledger. Every route requires a valid Sanctum token and
    // is scoped to the authenticated user's business inside the service layer.
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Categories — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::apiResource('categories', CategoryController::class);

        // Products — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::apiResource('products', ProductController::class);

        // Customers — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::apiResource('customers', CustomerController::class);

        // Suppliers — full CRUD (GET/POST, GET/PUT/PATCH/DELETE).
        Route::apiResource('suppliers', SupplierController::class);

        // Purchases — full CRUD; creation posts stock-in movements to the
        // ledger inside one transaction, and updates/deletes of
        // inventory-affecting purchases are refused by the service layer.
        Route::apiResource('purchases', PurchaseController::class);

        // Sales — full CRUD; creation deducts ledger stock in one transaction.
        Route::apiResource('sales', SaleController::class);
        Route::post('/sales/{sale}/payments', [SaleController::class, 'pay'])
            ->name('sales.payments.store');

        // Sale returns — list/create/show; stock restored via ledger.
        Route::apiResource('sale-returns', SaleReturnController::class)->only(['index', 'store', 'show']);

        // Phase 23 — expenses, cash drawer, read-only reports and dashboard.
        // Route ids are raw values; services resolve them inside the authenticated
        // business so cross-tenant records are indistinguishable from missing ones.
        Route::apiResource('expense-categories', ExpenseCategoryController::class);
        Route::apiResource('expenses', ExpenseController::class);

        Route::post('/cash-registers/open', [CashRegisterController::class, 'open'])
            ->name('cash-registers.open');
        Route::get('/cash-registers/current', [CashRegisterController::class, 'current'])
            ->name('cash-registers.current');
        Route::get('/cash-registers/{cashRegister}/transactions', [CashRegisterController::class, 'transactions'])
            ->name('cash-registers.transactions.index');
        Route::post('/cash-registers/{cashRegister}/transactions', [CashRegisterController::class, 'storeTransaction'])
            ->name('cash-registers.transactions.store');
        Route::get('/cash-registers/{cashRegister}/summary', [CashRegisterController::class, 'summary'])
            ->name('cash-registers.summary');
        Route::get('/cash-registers/{cashRegister}', [CashRegisterController::class, 'show'])
            ->name('cash-registers.show');
        Route::post('/cash-registers/{cashRegister}/close', [CashRegisterController::class, 'close'])
            ->name('cash-registers.close');

        Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
        Route::get('/reports/purchases', [ReportController::class, 'purchases'])->name('reports.purchases');
        Route::get('/reports/expenses', [ReportController::class, 'expenses'])->name('reports.expenses');
        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.profit-loss');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
        Route::get('/reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');

        // Inventory — movement log + manual stock adjustment. Stock is only
        // ever written through the ledger, never through a product field.
        Route::get('/stock-movements', [StockMovementController::class, 'index'])
            ->name('stock-movements.index');
        Route::post('/products/{product}/stock-adjustments', [StockMovementController::class, 'adjust'])
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
