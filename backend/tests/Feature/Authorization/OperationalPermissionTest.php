<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * P1-2 — operational domain permission enforcement.
 *
 * These tests exercise the *real* HTTP endpoints rather than the middleware in
 * isolation, because the risk this phase closes is a caller reaching a business
 * operation they hold no capability for. The rule under test everywhere is the
 * same three-way contract:
 *
 *   no token             -> 401 Unauthenticated
 *   token, no capability -> 403 Forbidden
 *   token + capability   -> not 401/403 (the domain rules decide the rest)
 *
 * The 401/403 boundary matters as much as the denial itself: a suspended or
 * anonymous caller must never receive a 403, because that would confirm the
 * endpoint exists and report the failure under the wrong status code.
 */
class OperationalPermissionTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /**
     * One row per protected operational action: capability => [method, uri].
     *
     * `{id}` is substituted with an id that exists inside the authenticated
     * business, so a denial can only come from the permission check and never
     * from a 404 raised by tenant scoping.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private const ENDPOINTS = [
        PermissionService::CATEGORIES_VIEW => ['GET', '/api/v1/categories'],
        PermissionService::CATEGORIES_CREATE => ['POST', '/api/v1/categories'],
        PermissionService::CATEGORIES_UPDATE => ['PUT', '/api/v1/categories/{id}'],
        PermissionService::CATEGORIES_DELETE => ['DELETE', '/api/v1/categories/{id}'],

        PermissionService::PRODUCTS_VIEW => ['GET', '/api/v1/products'],
        PermissionService::PRODUCTS_CREATE => ['POST', '/api/v1/products'],
        PermissionService::PRODUCTS_UPDATE => ['PUT', '/api/v1/products/{id}'],
        PermissionService::PRODUCTS_DELETE => ['DELETE', '/api/v1/products/{id}'],

        PermissionService::INVENTORY_VIEW => ['GET', '/api/v1/stock-movements'],
        PermissionService::INVENTORY_MANAGE => ['POST', '/api/v1/products/{id}/stock-adjustments'],

        PermissionService::CUSTOMERS_VIEW => ['GET', '/api/v1/customers'],
        PermissionService::CUSTOMERS_CREATE => ['POST', '/api/v1/customers'],
        PermissionService::CUSTOMERS_UPDATE => ['PUT', '/api/v1/customers/{id}'],
        PermissionService::CUSTOMERS_DELETE => ['DELETE', '/api/v1/customers/{id}'],

        PermissionService::SUPPLIERS_VIEW => ['GET', '/api/v1/suppliers'],
        PermissionService::SUPPLIERS_CREATE => ['POST', '/api/v1/suppliers'],
        PermissionService::SUPPLIERS_UPDATE => ['PUT', '/api/v1/suppliers/{id}'],
        PermissionService::SUPPLIERS_DELETE => ['DELETE', '/api/v1/suppliers/{id}'],

        PermissionService::PURCHASES_VIEW => ['GET', '/api/v1/purchases'],
        PermissionService::PURCHASES_CREATE => ['POST', '/api/v1/purchases'],
        PermissionService::PURCHASES_UPDATE => ['PUT', '/api/v1/purchases/{id}'],
        PermissionService::PURCHASES_DELETE => ['DELETE', '/api/v1/purchases/{id}'],

        PermissionService::SALES_VIEW => ['GET', '/api/v1/sales'],
        PermissionService::SALES_CREATE => ['POST', '/api/v1/sales'],
        PermissionService::SALES_UPDATE => ['PUT', '/api/v1/sales/{id}'],
        PermissionService::SALES_DELETE => ['DELETE', '/api/v1/sales/{id}'],
        PermissionService::SALES_PAYMENTS_CREATE => ['POST', '/api/v1/sales/{id}/payments'],

        PermissionService::SALE_RETURNS_VIEW => ['GET', '/api/v1/sale-returns'],
        PermissionService::SALE_RETURNS_CREATE => ['POST', '/api/v1/sale-returns'],

        PermissionService::EXPENSES_VIEW => ['GET', '/api/v1/expenses'],
        PermissionService::EXPENSES_CREATE => ['POST', '/api/v1/expenses'],
        PermissionService::EXPENSES_UPDATE => ['PUT', '/api/v1/expenses/{id}'],
        PermissionService::EXPENSES_DELETE => ['DELETE', '/api/v1/expenses/{id}'],

        PermissionService::EXPENSE_CATEGORIES_VIEW => ['GET', '/api/v1/expense-categories'],
        PermissionService::EXPENSE_CATEGORIES_CREATE => ['POST', '/api/v1/expense-categories'],

        PermissionService::CASH_REGISTERS_VIEW => ['GET', '/api/v1/cash-registers/current'],
        PermissionService::CASH_REGISTERS_CREATE => ['POST', '/api/v1/cash-registers/open'],
        PermissionService::CASH_REGISTERS_UPDATE => ['POST', '/api/v1/cash-registers/{id}/close'],
        PermissionService::CASH_REGISTERS_MANAGE => ['POST', '/api/v1/cash-registers/{id}/transactions'],
        PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE => ['PUT', '/api/v1/cash-registers/{id}/transactions/{id}'],
        PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE => ['DELETE', '/api/v1/cash-registers/{id}/transactions/{id}'],

        PermissionService::REPORTS_VIEW => ['GET', '/api/v1/reports/dashboard'],

        PermissionService::USERS_VIEW => ['GET', '/api/v1/users'],
        PermissionService::USERS_CREATE => ['POST', '/api/v1/users'],
        PermissionService::USERS_UPDATE => ['PUT', '/api/v1/users/{id}'],
        PermissionService::USERS_ROLES_UPDATE => ['PUT', '/api/v1/users/{id}/roles'],

        PermissionService::ROLES_VIEW => ['GET', '/api/v1/roles'],
        PermissionService::ROLES_CREATE => ['POST', '/api/v1/roles'],
        PermissionService::ROLES_UPDATE => ['PUT', '/api/v1/roles/{id}'],
        PermissionService::ROLES_DELETE => ['DELETE', '/api/v1/roles/{id}'],

        PermissionService::PERMISSIONS_VIEW => ['GET', '/api/v1/permissions'],

        PermissionService::SETTINGS_VIEW => ['GET', '/api/v1/settings'],
        PermissionService::SETTINGS_UPDATE => ['PUT', '/api/v1/settings'],
    ];

    /**
     * The 15 retired coarse permission slugs, plus the never-real `reports.manage`.
     *
     * None of them may be accepted by any capability, seeded, or resolvable.
     * Listed here as a fixture so the regression is stated once and enforced
     * against every capability rather than a handful.
     *
     * @var array<int, string>
     */
    private const RETIRED_SLUGS = [
        'products.manage',
        'sales.manage',
        'purchases.manage',
        'customers.manage',
        'suppliers.manage',
        'expenses.manage',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'inventory.manage',
        'cash_registers.manage',
        'categories.manage',
        'sale_returns.manage',
        'expense_categories.manage',
        'reports.manage',
    ];

    /** Substitute `{id}` with a real in-tenant category id. */
    private function uriFor(string $template, int $id): string
    {
        return str_replace('{id}', (string) $id, $template);
    }

    /** A minimal valid body; the permission check runs before validation. */
    private function payload(): array
    {
        return ['name' => 'Probe'];
    }

    /** @test */
    public function every_protected_operation_requires_authentication(): void
    {
        $business = Business::factory()->create();
        $id = Category::factory()->create(['business_id' => $business->id])->id;

        $this->flushHeaders();

        foreach (self::ENDPOINTS as [$method, $template]) {
            $this->json($method, $this->uriFor($template, $id), $this->payload())
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function an_authenticated_user_without_the_capability_receives_403(): void
    {
        $business = Business::factory()->create();
        $id = Category::factory()->create(['business_id' => $business->id])->id;

        foreach (self::ENDPOINTS as [$method, $template]) {
            // A fresh identity per endpoint: the previous one may have held a
            // role, and the point is that *this* request holds no capability.
            $this->actingAsUnprivilegedUser($business);

            $this->json($method, $this->uriFor($template, $id), $this->payload())
                ->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Forbidden',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function a_user_holding_only_the_capability_is_not_rejected_by_authorization(): void
    {
        $business = Business::factory()->create();
        $id = Category::factory()->create(['business_id' => $business->id])->id;

        foreach (self::ENDPOINTS as $capability => [$method, $template]) {
            $this->userWithPermissions([$capability], $business->id);

            $response = $this->json($method, $this->uriFor($template, $id), $this->payload());

            $this->assertNotContains(
                $response->getStatusCode(),
                [401, 403],
                "{$method} {$template} must not reject a user holding {$capability}.",
            );
        }
    }

    /** @test */
    public function read_and_write_capabilities_are_not_interchangeable(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        // A reader may not write or delete.
        $this->userWithPermissions([PermissionService::PRODUCTS_VIEW], $business->id);
        $this->postJson('/api/v1/products', ['name' => 'Nope', 'sku' => 'NOPE'])->assertStatus(403);
        $this->deleteJson('/api/v1/products/'.$product->id)->assertStatus(403);

        // A creator may not read back or delete.
        $this->userWithPermissions([PermissionService::PRODUCTS_CREATE], $business->id);
        $this->getJson('/api/v1/products')->assertStatus(403);
        $this->deleteJson('/api/v1/products/'.$product->id)->assertStatus(403);

        // A deleter may not read back or create.
        $this->userWithPermissions([PermissionService::PRODUCTS_DELETE], $business->id);
        $this->getJson('/api/v1/products')->assertStatus(403);
        $this->postJson('/api/v1/products', ['name' => 'Nope', 'sku' => 'NOPE'])->assertStatus(403);
    }

    /** @test */
    public function delete_requires_the_delete_capability_and_nothing_else(): void
    {
        $business = Business::factory()->create();
        $category = Category::factory()->create(['business_id' => $business->id]);

        // Every other capability for the same resource must not unlock delete.
        foreach ([
            PermissionService::CATEGORIES_VIEW,
            PermissionService::CATEGORIES_CREATE,
            PermissionService::CATEGORIES_UPDATE,
        ] as $capability) {
            $this->userWithPermissions([$capability], $business->id);

            $this->deleteJson('/api/v1/categories/'.$category->id)->assertStatus(403);
        }

        // The record must survive every one of those attempts.
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    /** @test */
    public function recording_a_sale_payment_does_not_require_creating_sales(): void
    {
        $business = Business::factory()->create();

        // Taking money is its own capability: collecting payment does not imply
        // the authority to raise or amend a sale.
        $this->userWithPermissions([PermissionService::SALES_PAYMENTS_CREATE], $business->id);

        $this->getJson('/api/v1/sales')->assertStatus(403);
        $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(403);
    }

    /** @test */
    public function stock_adjustment_uses_the_inventory_manage_capability(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        // Reading the ledger is not authority to correct it.
        $this->userWithPermissions([PermissionService::INVENTORY_VIEW], $business->id);

        $this->postJson('/api/v1/products/'.$product->id.'/stock-adjustments', [
            'type' => 'receive',
            'quantity' => 5,
        ])->assertStatus(403);

        $this->userWithPermissions([PermissionService::INVENTORY_MANAGE], $business->id);

        $this->postJson('/api/v1/products/'.$product->id.'/stock-adjustments', [
            'type' => 'receive',
            'quantity' => 5,
        ])->assertStatus(200);
    }

    /** @test */
    public function cash_register_operations_use_their_own_capabilities(): void
    {
        $business = Business::factory()->create();

        // Registers are per-user (`openQuery()` scopes on `opened_by`), so each
        // identity below must open its own shift before reading `/current`.
        // Only the opening capability is granted, which also proves `create` is
        // genuinely required to start a shift.
        $opener = $this->userWithPermissions([PermissionService::CASH_REGISTERS_CREATE], $business->id);
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100])->assertStatus(201);

        // A holder of `*.view` may read their own shift but may not open one.
        $viewer = $this->userWithPermissions([PermissionService::CASH_REGISTERS_VIEW], $business->id);
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100])->assertStatus(403);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(404);

        // Once that same user holds `*.view` *and* the opening capability, the
        // read succeeds — proving the earlier 404 was the missing register and
        // not a permission failure, and that the 403 above was the gate.
        $this->grantCapabilityTo($viewer, PermissionService::CASH_REGISTERS_CREATE);
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100])->assertStatus(201);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(200);

        // Recording a cash movement is a distinct capability from reading the
        // shift, and neither implies the other. The 403 below is the gate: a
        // holder of only the cash-movement permission cannot read `/current` at
        // all, which is the sharper form of the same separation.
        $mover = $this->userWithPermissions([PermissionService::CASH_REGISTERS_MANAGE], $business->id);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(403);

        // Given the read capability as well, the same request reaches the service
        // and gets the domain 404 ("no register is currently open") instead —
        // proving the earlier 403 was the permission check, not the domain.
        $this->grantCapabilityTo($mover, PermissionService::CASH_REGISTERS_VIEW);
        $this->getJson('/api/v1/cash-registers/current')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'No cash register is currently open.']);

        // Finally, the opener — who does hold a register — reads it once given
        // the read capability. Every `current` assertion above is a domain 404;
        // this one proves the endpoint is reachable and working.
        $this->userWithPrivilegedRegisterOpener($opener);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(200);
    }

    /**
     * Give a user `cash_registers.view` in addition to what they already hold.
     *
     * Named for the assertion it enables, so the final line of the cash-register
     * test reads as "the opener can now read the drawer" rather than as another
     * round of fixture wiring.
     */
    private function userWithPrivilegedRegisterOpener(User $user): void
    {
        $this->grantCapabilityTo($user, PermissionService::CASH_REGISTERS_VIEW);
    }

    /**
     * Add one capability to the role a user already holds.
     *
     * Used where a test needs to widen a single identity part-way through
     * without the widening being confused with the assertion under test.
     */
    private function grantCapabilityTo(User $user, string $capability): void
    {
        $permission = $this->permissionsBySlug([$capability])->first();

        $role = $user->roles()->first()
            ?? Role::create([
                'business_id' => $user->business_id,
                'name' => 'Test Role',
                'slug' => 'test-role-'.$user->getKey(),
            ]);

        $role->permissions()->syncWithoutDetaching([$permission->getKey()]);

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->authenticate($user->fresh());
    }

    /** @test */
    public function role_assignment_uses_the_users_roles_update_capability(): void
    {
        $business = Business::factory()->create();
        $member = User::factory()->create(['business_id' => $business->id]);

        // Managing user accounts is not the same authority as deciding who
        // holds which role — the latter is the privilege-escalation surface.
        $this->userWithPermissions([PermissionService::USERS_UPDATE], $business->id);
        $this->putJson('/api/v1/users/'.$member->id.'/roles', ['role_ids' => []])->assertStatus(403);

        $this->userWithPermissions([PermissionService::USERS_ROLES_UPDATE], $business->id);
        $this->putJson('/api/v1/users/'.$member->id.'/roles', ['role_ids' => []])->assertStatus(200);
    }

    /** @test */
    public function role_permission_sync_uses_the_roles_update_capability(): void
    {
        $business = Business::factory()->create();
        $role = Role::create([
            'business_id' => $business->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        // Creating a role is not authority to re-grant permissions on one.
        $this->userWithPermissions([PermissionService::ROLES_CREATE], $business->id);
        $this->putJson('/api/v1/roles/'.$role->id, ['name' => 'Renamed'])->assertStatus(403);

        $this->userWithPermissions([PermissionService::ROLES_UPDATE], $business->id);
        $this->putJson('/api/v1/roles/'.$role->id, ['name' => 'Renamed'])->assertStatus(200);
    }

    /** @test */
    public function a_permission_from_another_business_never_grants_anything(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        // A user of business A holding a role owned by business B that carries
        // the *entire* catalogue. If business scoping were ever weakened, this
        // is the fixture that would catch it.
        $user = User::factory()->create(['business_id' => $businessA->id]);
        $foreignRole = Role::create([
            'business_id' => $businessB->id,
            'name' => 'Foreign Admin',
            'slug' => 'foreign-admin',
        ]);
        $foreignRole->permissions()->attach(
            $this->permissionsBySlug(PermissionService::ALL_CAPABILITIES)->pluck('id')->all()
        );
        $user->roles()->attach($foreignRole);

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $fresh = $user->fresh();

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $this->assertFalse(
                $fresh->hasPermission($capability),
                "A business B role must not grant {$capability} to a business A user.",
            );
        }

        // The same must hold at the HTTP layer, not only in the model.
        $this->authenticate($fresh);
        $this->getJson('/api/v1/products')->assertStatus(403);
    }

    /** @test */
    public function the_canonical_fine_grained_permissions_authorise_their_own_domain(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        // The explicit action permissions for each domain, granted the way a
        // tenant would grant them through the roles API.
        $this->userWithPermissions([
            PermissionService::PRODUCTS_VIEW,
            PermissionService::CATEGORIES_VIEW,
            PermissionService::INVENTORY_VIEW,
            PermissionService::INVENTORY_MANAGE,
            PermissionService::SALES_VIEW,
            PermissionService::CASH_REGISTERS_VIEW,
            PermissionService::REPORTS_VIEW,
        ], $business->id);

        $this->getJson('/api/v1/products')->assertStatus(200);
        $this->getJson('/api/v1/categories')->assertStatus(200);
        $this->getJson('/api/v1/stock-movements')->assertStatus(200);
        $this->getJson('/api/v1/reports/dashboard')->assertStatus(200);
        $this->postJson('/api/v1/products/'.$product->id.'/stock-adjustments', [
            'type' => 'receive',
            'quantity' => 5,
        ])->assertStatus(200);
    }

    /** @test */
    public function a_retired_manage_slug_no_longer_authorises_its_domain(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        // A role holding only the retired coarse rows. The database cleanup
        // migration has not run yet, so those rows can still exist here; the
        // point is that the *runtime* no longer recognises them. Every request
        // below must be refused, exactly as for a user with no permissions.
        //
        // `userWithLiteralPermissions()`, not `userWithPermissions()`: two of
        // these slugs are also live capability names, so they have to be planted
        // verbatim or the fixture would grant the canonical permission instead.
        $this->userWithLiteralPermissions([
            'products.manage',
            'categories.manage',
            'inventory.manage',
            'sales.manage',
            'cash_registers.manage',
        ], $business->id);

        $this->getJson('/api/v1/products')->assertStatus(403);
        $this->getJson('/api/v1/categories')->assertStatus(403);
        $this->getJson('/api/v1/stock-movements')->assertStatus(403);
        $this->getJson('/api/v1/sales')->assertStatus(403);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(403);
        $this->postJson('/api/v1/products/'.$product->id.'/stock-adjustments', [
            'type' => 'receive',
            'quantity' => 5,
        ])->assertStatus(403);
    }

    /** @test */
    public function expense_categories_follow_their_own_fine_grained_permissions(): void
    {
        $business = Business::factory()->create();

        // `expenses.manage` used to alias every `expense_categories.*`
        // capability. Filing an expense under a category is not the same
        // authority as editing the category list, so the two domains are now
        // granted separately.
        $holder = $this->userWithLiteralPermissions(['expenses.manage'], $business->id);

        $this->assertFalse($holder->fresh()->hasPermission(PermissionService::EXPENSE_CATEGORIES_VIEW));
        $this->assertFalse($holder->fresh()->hasPermission(PermissionService::EXPENSE_CATEGORIES_CREATE));
        $this->getJson('/api/v1/expense-categories')->assertStatus(403);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(403);

        // The canonical expense-category permissions do the job on their own.
        $this->userWithPermissions([
            PermissionService::EXPENSE_CATEGORIES_VIEW,
            PermissionService::EXPENSE_CATEGORIES_CREATE,
        ], $business->id);

        $this->getJson('/api/v1/expense-categories')->assertStatus(200);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(201);
    }

    /** @test */
    public function the_expenses_domain_and_its_categories_do_not_imply_each_other(): void
    {
        $business = Business::factory()->create();

        $expensesOnly = $this->userWithPermissions([
            PermissionService::EXPENSES_VIEW,
            PermissionService::EXPENSES_CREATE,
            PermissionService::EXPENSES_UPDATE,
            PermissionService::EXPENSES_DELETE,
        ], $business->id);

        foreach ([
            PermissionService::EXPENSE_CATEGORIES_VIEW,
            PermissionService::EXPENSE_CATEGORIES_CREATE,
            PermissionService::EXPENSE_CATEGORIES_UPDATE,
            PermissionService::EXPENSE_CATEGORIES_DELETE,
        ] as $capability) {
            $this->assertFalse(
                $expensesOnly->fresh()->hasPermission($capability),
                "The expenses domain must not imply {$capability}.",
            );
        }

        $categoriesOnly = $this->userWithPermissions([
            PermissionService::EXPENSE_CATEGORIES_VIEW,
            PermissionService::EXPENSE_CATEGORIES_CREATE,
            PermissionService::EXPENSE_CATEGORIES_UPDATE,
            PermissionService::EXPENSE_CATEGORIES_DELETE,
        ], $business->id);

        foreach ([
            PermissionService::EXPENSES_VIEW,
            PermissionService::EXPENSES_CREATE,
            PermissionService::EXPENSES_UPDATE,
            PermissionService::EXPENSES_DELETE,
        ] as $capability) {
            $this->assertFalse(
                $categoriesOnly->fresh()->hasPermission($capability),
                "The expense-category domain must not imply {$capability}.",
            );
        }
    }

    /** @test */
    public function taking_a_payment_remains_its_own_canonical_permission(): void
    {
        $business = Business::factory()->create();

        // `sales.payments.create` survived the taxonomy change as a permission in
        // its own right, and the retired `sales.manage` row is not an alias for
        // it any more.
        $this->assertSame(
            [PermissionService::SALES_PAYMENTS_CREATE],
            PermissionService::slugsFor(PermissionService::SALES_PAYMENTS_CREATE),
        );

        $user = $this->userWithPermissions([PermissionService::SALES_PAYMENTS_CREATE], $business->id);

        $this->assertTrue($user->hasPermission(PermissionService::SALES_PAYMENTS_CREATE));
        $this->assertFalse($user->hasPermission(PermissionService::SALES_CREATE));
        $this->assertFalse($user->hasPermission(PermissionService::SALES_VIEW));

        $coarseHolder = $this->userWithLiteralPermissions(['sales.manage'], $business->id);

        $this->assertFalse(
            $coarseHolder->fresh()->hasPermission(PermissionService::SALES_PAYMENTS_CREATE),
            'sales.manage must not be an alias for sales.payments.create.',
        );
    }
    // ------------------------------------------------------------------
    // Capability-set regression
    // ------------------------------------------------------------------

    /**
     * @test
     *
     * The 14 coarse `<resource>.manage` slugs that used to stand in for whole
     * domains are gone from the active alias configuration. No capability may
     * resolve through one of them any more.
     *
     * `inventory.manage` and `cash_registers.manage` are *not* excused by this
     * list even though they are capability names: what matters is the *slug* a
     * capability resolves through, and those two resolve through
     * `inventory.adjust` and `cash_registers.transactions.create`.
     */
    public function no_capability_resolves_through_a_retired_manage_slug(): void
    {
        foreach (self::RETIRED_SLUGS as $slug) {
            foreach (PermissionService::ALL_CAPABILITIES as $capability) {
                $this->assertNotContains(
                    $slug,
                    PermissionService::slugsFor($capability),
                    "{$capability} must not resolve through the retired slug {$slug}.",
                );
            }

            $this->assertNotContains(
                $slug,
                PermissionService::catalogueSlugs(),
                "The retired slug {$slug} must not be in the canonical catalogue.",
            );
        }
    }

    /** @test */
    public function every_capability_resolves_through_exactly_one_canonical_slug(): void
    {
        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $slugs = PermissionService::slugsFor($capability);

            $this->assertTrue(PermissionService::isKnown($capability), "{$capability} must be a known capability.");
            $this->assertCount(1, $slugs, "{$capability} must resolve through exactly one slug.");
            $this->assertSame($slugs[0], PermissionService::slugFor($capability));
        }
    }

    /** @test */
    public function the_two_non_identity_capabilities_resolve_to_their_action_permissions(): void
    {
        // Only these two have a name that differs from their slug, and the
        // difference is deliberate: the names are API surface (routes, `can:`
        // middleware, the `capabilities` list) and cannot be renamed, while the
        // slugs are free to describe the action the write actually performs.
        $expected = [
            PermissionService::INVENTORY_MANAGE => PermissionService::INVENTORY_ADJUST,
            PermissionService::CASH_REGISTERS_MANAGE => PermissionService::CASH_REGISTERS_TRANSACTIONS_CREATE,
        ];

        foreach ($expected as $capability => $slug) {
            $this->assertSame([$slug], PermissionService::slugsFor($capability));
            $this->assertContains($slug, PermissionService::catalogueSlugs());
        }

        // Every other capability is its own permission.
        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            if (array_key_exists($capability, $expected)) {
                continue;
            }

            $this->assertSame(
                [$capability],
                PermissionService::slugsFor($capability),
                "{$capability} should be its own permission.",
            );
        }
    }

    /** @test */
    public function the_catalogue_contains_exactly_the_capability_slugs_and_no_orphans(): void
    {
        $catalogue = PermissionService::catalogueSlugs();

        $this->assertSame(
            array_values(array_unique($catalogue)),
            $catalogue,
            'catalogueSlugs() must be de-duplicated.',
        );

        $expected = array_map(
            static fn (string $capability): string => PermissionService::slugFor($capability),
            PermissionService::ALL_CAPABILITIES,
        );

        sort($expected);
        $sorted = $catalogue;
        sort($sorted);

        $this->assertSame(
            $expected,
            $sorted,
            'The catalogue must be exactly the canonical slug of every capability — no orphans, none missing.',
        );
    }

    /** @test */
    public function resolved_capabilities_agree_with_actual_gate_authorization(): void
    {
        $business = Business::factory()->create();
        $service = app(PermissionService::class);

        foreach ([
            PermissionService::PRODUCTS_VIEW,
            PermissionService::INVENTORY_MANAGE,
            PermissionService::CASH_REGISTERS_MANAGE,
            PermissionService::SALES_PAYMENTS_CREATE,
            PermissionService::EXPENSE_CATEGORIES_VIEW,
        ] as $capability) {
            // Holding exactly the canonical permission behind this capability.
            $user = $this->userWithPermissions([$capability], $business->id)->fresh();

            $this->assertContains(
                $capability,
                $service->resolvedCapabilities($user),
                "{$capability} must be published once its permission is held.",
            );

            $this->assertTrue(
                Gate::forUser($user)->allows($capability),
                "The Gate must agree that {$capability} is granted.",
            );

            // And the published list is never wider than the Gate actually allows.
            foreach ($service->resolvedCapabilities($user) as $published) {
                $this->assertTrue(
                    Gate::forUser($user)->allows($published),
                    "resolvedCapabilities() published {$published}, which the Gate refuses.",
                );
            }
        }
    }

    /** @test */
    public function user_override_precedence_survives_the_permission_taxonomy_change(): void
    {
        $business = Business::factory()->create();
        $service = app(PermissionService::class);
        $user = $this->userWithPermissions([PermissionService::SALES_VIEW], $business->id);

        $this->assertTrue($user->hasPermission(PermissionService::SALES_VIEW));

        $this->seedOverride($user, PermissionService::SALES_VIEW, 'deny');

        $this->assertFalse($user->fresh()->hasPermission(PermissionService::SALES_VIEW));
        $this->assertNotContains(
            PermissionService::SALES_VIEW,
            $service->resolvedCapabilities($user->fresh()),
        );
        $this->assertFalse(Gate::forUser($user->fresh())->allows(PermissionService::SALES_VIEW));
    }

    /**
     * Write a per-user override directly, the way the management API would.
     */
    private function seedOverride(User $user, string $slug, string $effect): void
    {
        $permission = $this->literalPermission($slug);

        DB::table('user_permissions')->insert([
            'business_id' => $user->business_id,
            'user_id' => $user->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function a_403_never_leaks_permission_or_database_internals(): void
    {
        $this->actingAsUnprivilegedUser(Business::factory()->create());

        $body = (string) $this->getJson('/api/v1/products')->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('permission_id', $body);
        $this->assertStringNotContainsString('permission_role', $body);
    }

    /** @test */
    public function a_permission_failure_is_always_403_never_401_or_500(): void
    {
        $business = Business::factory()->create();
        $id = Category::factory()->create(['business_id' => $business->id])->id;

        foreach (self::ENDPOINTS as [$method, $template]) {
            $this->actingAsUnprivilegedUser($business);

            $status = $this->json($method, $this->uriFor($template, $id), $this->payload())
                ->getStatusCode();

            $this->assertSame(
                403,
                $status,
                "{$method} {$template} must answer 403 (got {$status}) for a valid token without the capability.",
            );
        }
    }

    /** @test */
    public function an_inactive_user_is_blocked_with_401_not_403(): void
    {
        $business = Business::factory()->create();
        $id = Category::factory()->create(['business_id' => $business->id])->id;

        $user = $this->actingAsBusinessUser($business);
        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        // `active` runs before the permission gate, so a suspended account is
        // unauthenticated (401) even though it holds every capability.
        $this->getJson('/api/v1/categories/'.$id)
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /** @test */
    public function every_capability_is_resolvable_by_the_gate(): void
    {
        // Guards against a route naming a capability that was never registered:
        // Laravel's `can:` middleware fails closed on an unknown ability, which
        // would leave the endpoint permanently unreachable.
        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $this->assertTrue(
                Gate::has($capability),
                "Gate ability {$capability} is not registered.",
            );

            $this->assertNotEmpty(
                PermissionService::slugsFor($capability),
                "{$capability} resolves to no permission slug.",
            );
        }
    }

    /** @test */
    public function a_user_with_no_role_holds_no_operational_capability(): void
    {
        $user = $this->actingAsUnprivilegedUser(Business::factory()->create());

        foreach (PermissionService::ALL_CAPABILITIES as $capability) {
            $this->assertFalse(
                $user->fresh()->hasPermission($capability),
                "A user without roles must not hold {$capability}.",
            );
        }
    }
}
