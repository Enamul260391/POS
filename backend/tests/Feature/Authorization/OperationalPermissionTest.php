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

        // Recording a cash movement is `cash_registers.manage`, and that alone
        // does not imply read access to the shift. The 404 below is the service
        // reporting "this user has no open drawer" — it proves the permission
        // gate let the request through, which is the point: a reader-only role
        // would have been stopped with 403 before reaching the service.
        $this->userWithPermissions([PermissionService::CASH_REGISTERS_MANAGE], $business->id);
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
    public function the_legacy_manage_permissions_still_authorise_their_domain(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        // A role seeded before the fine-grained catalogue existed holds only the
        // coarse rows. It must keep the whole domain it always had, otherwise
        // this phase would silently lock out every existing installation.
        $this->userWithPermissions([
            'products.manage',
            'categories.manage',
            'inventory.manage',
            'sales.manage',
            'cash_registers.manage',
            'reports.view',
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
    public function expense_categories_follow_the_expenses_manage_permission(): void
    {
        $business = Business::factory()->create();

        // Expense categories are a sub-resource of expenses, so the coarse
        // `expenses.manage` row has to keep working for them.
        $this->userWithPermissions(['expenses.manage'], $business->id);

        $this->getJson('/api/v1/expense-categories')->assertStatus(200);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(201);
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
