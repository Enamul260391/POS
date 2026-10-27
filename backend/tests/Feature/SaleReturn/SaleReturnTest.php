<?php

declare(strict_types=1);

namespace Tests\Feature\SaleReturn;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Payment;
use App\Models\InventoryBatch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * A return as a reversal of the sale's economic effects.
 *
 * The rule under test throughout: a return takes back what the sale took —
 * revenue, cost of goods, stock and cash — and records the reversal as its own
 * dated document without touching the original invoice.
 *
 * The suite is grouped by what each guarantee protects against: money, quantity,
 * stock, cash, cost, integrity, and safety.
 */
class SaleReturnTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->otherBusiness = Business::factory()->create();
    }

    /** A stock-keeping product with the given cost, holding 100 units. */
    private function product(string $costPrice = '60.00', string $sellingPrice = '100.00'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '100',
            'created_by' => $this->user->id,
        ]);

        // Pricing now lives on inventory batches. Stock in at the given cost
        // so sales through the API capture unit_cost from the batch snapshot.
        InventoryBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'purchase_item_id' => null,
            'batch_number' => 'TEST-'.fake()->unique()->numerify('######'),
            'received_date' => now()->toDateString(),
            'expiry_date' => null,
            'received_quantity' => '100',
            'remaining_quantity' => '100',
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'mrp' => null,
            'selling_code' => null,
        ]);

        return $product;
    }

    /**
     * Record a sale through the API and return `[payload, saleItemIds]`.
     *
     * Always over HTTP, so the fixture is a sale the rest of the system would
     * really have written — including its `unit_cost` snapshot and its payment
     * rows — rather than one hand-assembled in the database.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function sale(array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => now()->toDateString(),
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);
        $sale = $response->json('data');

        return [$sale, array_map('intval', array_column($sale['items'], 'id'))];
    }

    /** A fully cash-paid sale of `$quantity` units at `$unitPrice`. */
    private function cashSale(Product $product, string $quantity = '10', string $unitPrice = '100'): array
    {
        return $this->sale(
            [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            ['paid_amount' => Money::multiply($unitPrice, $quantity), 'payment_method' => Payment::PAYMENT_METHOD_CASH]
        );
    }

    /**
     * Post a return and assert it was accepted.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function returnSale(int $saleId, array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sale-returns', array_merge([
            'sale_id' => $saleId,
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);

        return $response->json('data');
    }

    /** Open this user's drawer, so automatic cash movements have somewhere to go. */
    private function openRegister(): CashRegister
    {
        $response = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 0]);
        $response->assertStatus(201);

        return CashRegister::findOrFail($response->json('data.id'));
    }

    /** The balance the API itself reports for a register. */
    private function balanceOf(CashRegister $register): string
    {
        return (string) $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertStatus(200)
            ->json('data.current_balance');
    }

    /** On-hand stock of a product, read from the API. */
    private function stockOf(Product $product): string
    {
        return (string) $this->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(200)
            ->json('data.current_stock');
    }

    /** Every refund movement posted on one register. */
    private function refundMovements(CashRegister $register): Collection
    {
        return CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_REFUND)
            ->get();
    }

    /** Every refund movement posted anywhere. */
    private function allRefunds(): Collection
    {
        return CashRegisterTransaction::query()
            ->where('type', CashRegisterTransaction::TYPE_REFUND)
            ->get();
    }

    /** A return of `$quantity` units of one invoice line. */
    private function line(int $saleItemId, int $quantity): array
    {
        return [['sale_item_id' => $saleItemId, 'quantity' => $quantity]];
    }

    /**
     * Give `$user` a role of their own holding exactly the given capabilities.
     *
     * The role is owned by the user's own business because `User::permissions()`
     * only counts roles whose `business_id` matches theirs — a role from another
     * tenant would grant nothing at all.
     *
     * Unlike `userWithPermissions()` this deliberately does **not** authenticate:
     * these tests need the default identity left alone so the fixture sale is
     * created by the tenant that owns it, and only the request under test runs
     * as the second identity.
     *
     * @param  array<int, string>  $capabilities
     */
    private function grantRoleWith(User $user, array $capabilities): void
    {
        $permissionIds = [];
        foreach ($capabilities as $capability) {
            $slug = PermissionService::slugFor($capability);
            $permissionIds[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace(['.', '_'], ' ', $slug))]
            )->getKey();
        }

        $role = Role::firstOrCreate(
            ['business_id' => $user->business_id, 'slug' => 'test-partial-role-'.$user->getKey()],
            ['name' => 'Test Partial Access', 'description' => 'Fixture role holding only the abilities under test.'],
        );
        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    // ------------------------------------------------------------------
    // Money: what the customer gets back
    // ------------------------------------------------------------------

    /** @test */
    public function a_full_return_refunds_the_whole_line_and_reverses_its_historical_cost(): void
    {
        $this->openRegister();
        $product = $this->product('60.00', '100.00');
        [$sale, $itemIds] = $this->cashSale($product);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        // 10 × ৳100 sold; the whole line comes back.
        $this->assertSame('1000.00', $ret['refund_amount']);
        $this->assertSame('1000.00', $ret['subtotal']);

        // The cost reversed is the cost *incurred*: 10 × the ৳60 snapshotted on
        // the invoice line, not the product's price of today.
        $this->assertSame('60.00', $ret['items'][0]['unit_cost']);
        $this->assertSame('600.00', $ret['items'][0]['returned_cost']);
        $this->assertSame('600.00', $ret['returned_cost']);

        // Net profit reversal: ৳1000 of revenue comes back against ৳600 of cost
        // that is put back, so the ৳400 of gross profit this sale contributed is
        // what the return gives back.
        $this->assertSame('400.00', Money::subtract('1000.00', '600.00'));
    }

    /** @test */
    public function a_partial_return_refunds_exactly_the_proportional_share(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        $this->assertSame('300.00', $ret['refund_amount']);
        $this->assertSame('3', $ret['items'][0]['quantity']);
        $this->assertSame('180.00', $ret['returned_cost'], '3 of 10 units at ৳60 each.');
    }

    /** @test */
    public function a_return_of_a_discounted_line_shares_out_the_stored_line_total(): void
    {
        $this->openRegister();
        $product = $this->product();
        // Gross 10 × 100 = 1000, less a ৳100 line discount → the stored line
        // total the return must share out is 900, not 1000.
        [$sale, $itemIds] = $this->sale([[
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 100,
            'discount' => 100,
        ]]);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 5));

        $this->assertSame('450.00', $ret['refund_amount']);
    }

    /** @test */
    public function several_return_lines_of_one_return_are_each_valued_and_summed(): void
    {
        $this->openRegister();
        $first = $this->product('60.00', '100.00');
        $second = $this->product('25.00', '50.00');
        [$sale, $itemIds] = $this->sale([
            ['product_id' => $first->id, 'quantity' => 4, 'unit_price' => 100],
            ['product_id' => $second->id, 'quantity' => 2, 'unit_price' => 50],
        ]);

        $ret = $this->returnSale($sale['id'], [
            ['sale_item_id' => $itemIds[0], 'quantity' => 2],
            ['sale_item_id' => $itemIds[1], 'quantity' => 2],
        ]);

        $this->assertCount(2, $ret['items']);
        $this->assertSame('200.00', $ret['items'][0]['total'], '2 × ৳100.');
        $this->assertSame('100.00', $ret['items'][1]['total'], '2 × ৳50.');
        $this->assertSame('300.00', $ret['refund_amount']);

        // 2 × ৳60 + 2 × ৳25.
        $this->assertSame('170.00', $ret['returned_cost']);
    }

    /** @test */
    public function a_returned_line_reverses_only_its_share_of_the_line_tax(): void
    {
        $this->openRegister();
        $product = $this->product('0.00', '100.00');
        [$sale, $itemIds] = $this->sale([[
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 100,
            'tax' => 100,
        ]]);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 5));

        // The stored line total is 1100 (1000 + 100 tax); half of it is the
        // refund, and half of the line tax comes back with it.
        $this->assertSame('550.00', $ret['refund_amount']);
        $this->assertSame('50.00', $ret['tax']);
    }

    // ------------------------------------------------------------------
    // Quantity: the remainder guard
    // ------------------------------------------------------------------

    /** @test */
    public function repeated_partial_returns_exhaust_the_line_exactly_once(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));
        $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // 10 sold, 7 already back: 3 remain.
        $third = $this->returnSale($sale['id'], $this->line($itemIds[0], 3));
        $this->assertSame('300.00', $third['refund_amount']);

        // The shares add back up to the line exactly: 300 + 400 + 300.
        $this->assertSame('1000.00', Money::format(
            DB::table('sale_returns')->where('sale_id', $sale['id'])->sum('refund_amount')
        ));

        // And the ledger restored those 10 units exactly once between them.
        $this->assertSame('100', $this->stockOf($product));
    }

    /** @test */
    public function a_return_above_the_remaining_quantity_is_refused(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        $before = $this->stockOf($product);
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 8),
        ])->assertStatus(409);

        // A refused return leaves nothing behind.
        $this->assertSame($before, $this->stockOf($product));
        $this->assertSame(1, SaleReturn::query()->where('sale_id', $sale['id'])->count());
    }

    /** @test */
    public function a_line_cannot_be_returned_again_after_it_is_fully_returned(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 10));
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(409);

        $this->assertSame(1, SaleReturn::query()->where('sale_id', $sale['id'])->count());
    }

    /** @test */
    public function a_replayed_return_request_returns_each_unit_only_once(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $payload = ['sale_id' => $sale['id'], 'items' => $this->line($itemIds[0], 2)];

        $this->postJson('/api/v1/sale-returns', $payload)->assertStatus(201);
        $this->postJson('/api/v1/sale-returns', $payload)->assertStatus(201);

        // An identical request is a genuine second partial return of 2 units, so
        // exactly 4 came back — never more, and never the same units twice.
        $this->assertSame(4.0, (float) DB::table('sale_return_items')
            ->where('sale_item_id', $itemIds[0])->sum('quantity'));
        $this->assertSame(2, SaleReturn::query()->where('sale_id', $sale['id'])->count());

        // 100 on hand − 10 sold + 4 returned.
        $this->assertSame('94', $this->stockOf($product));

        // And exactly two refunds were paid out, one per return, totalling ৳400.
        $this->assertCount(2, $this->allRefunds());
        $this->assertSame('400.00', Money::format(
            (string) $this->allRefunds()->sum(fn ($m): float => (float) $m->amount)
        ));
    }

    /** @test */
    public function the_same_sale_line_cannot_be_listed_twice_in_one_return(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [
                ['sale_item_id' => $itemIds[0], 'quantity' => 1],
                ['sale_item_id' => $itemIds[0], 'quantity' => 1],
            ],
        ])->assertStatus(409);

        $this->assertSame(0, SaleReturn::query()->where('sale_id', $sale['id'])->count());
    }

    /** @test */
    public function a_line_of_another_sale_cannot_be_returned_through_this_invoice(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$saleA] = $this->cashSale($product);
        [, $itemIdsB] = $this->cashSale($product);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $saleA['id'],
            'items' => $this->line($itemIdsB[0], 1),
        ])->assertStatus(409);

        $this->assertSame(0, SaleReturn::query()->where('sale_id', $saleA['id'])->count());
    }

    // ------------------------------------------------------------------
    // Inventory
    // ------------------------------------------------------------------

    /** @test */
    public function stock_is_restored_exactly_once_for_a_return(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // 100 on hand − 10 sold = 90.
        $this->assertSame('90', $this->stockOf($product));

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $this->assertSame('94', $this->stockOf($product));

        // Exactly one restoring movement, referencing this return, at exactly the
        // returned quantity.
        $movements = StockMovement::query()
            ->where('reference_type', SaleReturn::class)
            ->where('reference_id', $ret['id'])
            ->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovement::TYPE_SALE_RETURN, $movements->first()->type);
        $this->assertSame('4', Quantity::format($movements->first()->quantity));
        $this->assertSame($this->business->id, (int) $movements->first()->business_id);
    }

    /** @test */
    public function a_refused_return_restores_no_stock(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 11),
        ])->assertStatus(409);

        $this->assertSame('90', $this->stockOf($product));
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', SaleReturn::class)->count());
    }

    // ------------------------------------------------------------------
    // Cash
    // ------------------------------------------------------------------

    /** @test */
    public function a_cash_return_posts_one_automatic_refund_and_lowers_the_drawer_by_exactly_that(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $this->assertSame('1000.00', $this->balanceOf($register), 'The cash sale paid the drawer in.');

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // Exactly one refund movement, pointing at this return.
        $movements = $this->refundMovements($register);
        $this->assertCount(1, $movements);
        $this->assertSame('400.00', Money::format($movements->first()->amount));
        $this->assertSame(SaleReturn::class, $movements->first()->reference_type);
        $this->assertSame($ret['id'], (int) $movements->first()->reference_id);

        // The drawer falls by the refund and by nothing else.
        $this->assertSame('600.00', $this->balanceOf($register));
    }

    /** @test */
    public function each_cash_return_posts_its_own_single_movement(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $first = $this->returnSale($sale['id'], $this->line($itemIds[0], 3));
        $second = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $movements = $this->refundMovements($register);
        $this->assertCount(2, $movements, 'One per return — never one per line or per attempt.');
        $this->assertEqualsCanonicalizing(
            [$first['id'], $second['id']],
            $movements->map(fn ($m): int => (int) $m->reference_id)->all()
        );

        // ৳1000 in, ৳300 + ৳400 back out.
        $this->assertSame('300.00', $this->balanceOf($register));
    }

    /** @test */
    public function a_non_cash_return_does_not_move_the_drawer(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CARD]
        );

        $this->assertSame('0.00', $this->balanceOf($register), 'A card sale never entered the drawer.');

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // The return is still a real return…
        $this->assertSame('400.00', $ret['refund_amount']);
        $this->assertSame('94', $this->stockOf($product));

        // …but no cash left the drawer, because no cash ever arrived.
        $this->assertCount(0, $this->refundMovements($register));
        $this->assertSame('0.00', $this->balanceOf($register));
    }

    /** @test */
    public function a_credit_sale_return_moves_no_cash(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->sale([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]]);

        $this->assertSame('pending', $sale['payment_status']);
        $this->assertSame('1000.00', $sale['due_amount']);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $this->assertSame('400.00', $ret['refund_amount']);
        $this->assertCount(0, $this->refundMovements($register), 'Nothing was collected, so nothing can be handed back.');
        $this->assertSame('0.00', $this->balanceOf($register));

        // The sale keeps the invoice as written; the reversal lives on the
        // return document, which is what a report nets off.
        $this->assertSame('1000.00', Money::format(Sale::findOrFail($sale['id'])->due_amount));
    }

    /** @test */
    public function a_mixed_sale_refunds_only_the_cash_it_collected(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 400, 'payment_method' => Payment::PAYMENT_METHOD_CASH]
        );

        $this->assertSame('400.00', $this->balanceOf($register));

        // ৳800 of goods come back, but only ৳400 of cash ever entered the
        // drawer, so only ৳400 can leave it.
        $this->returnSale($sale['id'], $this->line($itemIds[0], 8));

        $movements = $this->refundMovements($register);
        $this->assertCount(1, $movements);
        $this->assertSame('400.00', Money::format($movements->first()->amount));
        $this->assertSame('0.00', $this->balanceOf($register));
    }

    /** @test */
    public function a_return_never_refunds_more_cash_than_the_sale_took(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 100, 'payment_method' => Payment::PAYMENT_METHOD_CASH]
        );

        // ৳100 of cash in, but ৳1000 of goods come back.
        $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $movements = $this->refundMovements($register);
        $this->assertCount(1, $movements);
        $this->assertSame('100.00', Money::format($movements->first()->amount), 'Capped at the cash actually taken.');
        $this->assertSame('0.00', $this->balanceOf($register));
    }

    /** @test */
    public function a_return_without_an_open_drawer_still_records_the_return(): void
    {
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // No `openRegister()` here: there is no drawer to take cash from.
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $this->assertSame('400.00', $ret['refund_amount']);
        $this->assertSame('94', $this->stockOf($product));
        $this->assertCount(0, $this->allRefunds());
    }

    /** @test */
    public function an_automatic_refund_movement_cannot_be_edited_through_the_manual_endpoint(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));
        $movement = $this->refundMovements($register)->first();

        $uri = "/api/v1/cash-registers/{$register->id}/transactions/{$movement->id}";

        // 409, not 403: the caller *is* authorized for the endpoint; this row is
        // simply not theirs to rewrite.
        $this->putJson($uri, ['amount' => 1])->assertStatus(409);
        $this->assertSame('400.00', Money::format($movement->fresh()->amount));
    }

    /** @test */
    public function an_automatic_refund_movement_cannot_be_deleted_through_the_manual_endpoint(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));
        $movement = $this->refundMovements($register)->first();

        $this->deleteJson("/api/v1/cash-registers/{$register->id}/transactions/{$movement->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $movement->id,
            'reference_type' => SaleReturn::class,
            'reference_id' => $ret['id'],
        ]);
        $this->assertSame('600.00', $this->balanceOf($register), 'The refund is still in the ledger.');
    }

    /** @test */
    public function the_manual_endpoint_cannot_be_used_to_forge_a_refund(): void
    {
        $register = $this->openRegister();

        // `refund` is not a manual type, so the create endpoint refuses it.
        $this->postJson("/api/v1/cash-registers/{$register->id}/transactions", [
            'type' => CashRegisterTransaction::TYPE_REFUND,
            'amount' => 500,
        ])->assertStatus(422);

        $this->assertCount(0, $this->allRefunds());
    }

    /** @test */
    public function a_refund_counts_toward_the_drawers_outflow_total(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);
        $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $summary = $this->getJson("/api/v1/cash-registers/{$register->id}/summary")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('1000.00', $summary['total_in']);
        $this->assertSame('400.00', $summary['total_out']);
        $this->assertSame('600.00', $summary['current_balance']);
    }

    // ------------------------------------------------------------------
    // Cost of goods
    // ------------------------------------------------------------------

    /** @test */
    public function the_reversal_uses_the_historical_cost_not_the_products_current_one(): void
    {
        $this->openRegister();
        $product = $this->product('60.00', '100.00');

        // Stock in at cost ৳60 so the sale captures it as unit_cost snapshot.
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => '100',
                'unit_cost' => '60.00',
                'selling_price' => '100.00',
            ]],
        ])->assertStatus(201);

        [$sale, $itemIds] = $this->cashSale($product);

        // A later purchase costs ৳80. The return must still value the reversal
        // at the ৳60 the invoice recorded, not the ৳80 from the new batch.
        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => '100',
                'unit_cost' => '80.00',
                'selling_price' => '100.00',
            ]],
        ])->assertStatus(201);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $this->assertSame('60.00', $ret['items'][0]['unit_cost']);
        $this->assertSame('600.00', $ret['returned_cost']);
    }

    /** @test */
    public function a_line_with_no_recorded_cost_withholds_the_figure_instead_of_inventing_one(): void
    {
        $this->openRegister();
        $priced = $this->product('60.00', '100.00');
        $unpriced = $this->product('0.00', '100.00');
        [$sale, $itemIds] = $this->sale([
            ['product_id' => $priced->id, 'quantity' => 10, 'unit_price' => 100],
            ['product_id' => $unpriced->id, 'quantity' => 10, 'unit_price' => 100],
        ]);

        // A line recorded before the cost column existed has no snapshot.
        SaleItem::query()->whereKey($itemIds[1])->update(['unit_cost' => null]);

        $ret = $this->returnSale($sale['id'], [
            ['sale_item_id' => $itemIds[0], 'quantity' => 10],
            ['sale_item_id' => $itemIds[1], 'quantity' => 10],
        ]);

        // The priced line still reports its own cost…
        $this->assertSame('600.00', $ret['items'][0]['returned_cost']);

        // …the unpriced one reports nothing rather than a confident ৳0 …
        $this->assertNull($ret['items'][1]['unit_cost']);
        $this->assertNull($ret['items'][1]['returned_cost']);

        // …and the header withholds the total instead of publishing a partial
        // figure that would understate the cost that came back.
        $this->assertNull($ret['returned_cost']);

        // The money is unaffected: the customer still gets the full refund.
        $this->assertSame('2000.00', $ret['refund_amount']);
    }

    // ------------------------------------------------------------------
    // The original sale stays intact, and the return carries its own date
    // ------------------------------------------------------------------

    /** @test */
    public function the_original_sale_is_left_exactly_as_it_was(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // Compared as plain scalars: `sale_date` is a Carbon cast, and two
        // separately-loaded Carbon instances are never `assertSame`-identical
        // even when they describe the same instant.
        $fields = [
            'sale_date', 'subtotal', 'discount', 'tax', 'grand_total',
            'paid_amount', 'due_amount', 'payment_status', 'status', 'invoice_number',
        ];
        $snapshot = fn (): array => array_map(
            static fn (string $field): mixed => $field === 'sale_date'
                ? Sale::findOrFail($sale['id'])->sale_date->toDateString()
                : Sale::findOrFail($sale['id'])->getAttribute($field),
            $fields
        );

        $before = $snapshot();

        $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $this->assertSame($before, $snapshot(), 'A return must not rewrite the invoice it reverses.');

        // The invoice lines are untouched too, cost snapshot included.
        $item = SaleItem::findOrFail($itemIds[0]);
        $this->assertSame('10', Quantity::format($item->quantity));
        $this->assertSame('1000.00', Money::format($item->total));
        $this->assertSame('60.00', Money::format($item->unit_cost));
    }

    /** @test */
    public function the_return_is_dated_when_it_happened_and_the_sale_when_it_was_sold(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);
        $this->assertSame('2026-10-01', Sale::findOrFail($sale['id'])->sale_date->toDateString());

        // Four days later the goods come back.
        $this->travelTo(Carbon::parse('2026-10-05 15:30:00'));
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 2));

        $this->assertSame('2026-10-05', $ret['return_date'], 'The return is dated when the return happened.');

        // The sale keeps its own date; the return does not drag it forward.
        $this->assertSame('2026-10-01', Sale::findOrFail($sale['id'])->sale_date->toDateString());

        // The stored timestamp is the real moment of the return.
        $this->assertSame(
            '2026-10-05 15:30:00',
            SaleReturn::findOrFail($ret['id'])->created_at->toDateTimeString()
        );
    }

    // ------------------------------------------------------------------
    // Atomicity
    // ------------------------------------------------------------------

    /** @test */
    public function a_failure_anywhere_rolls_the_whole_return_back(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        $returnsBefore = SaleReturn::query()->count();
        $movementsBefore = CashRegisterTransaction::query()->count();
        $stockBefore = $this->stockOf($product);
        $balanceBefore = $this->balanceOf($register);

        // The second line is invalid, so the whole request must fail even though
        // the first line was already processed in memory.
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [
                ['sale_item_id' => $itemIds[0], 'quantity' => 2],
                ['sale_item_id' => 999999, 'quantity' => 1],
            ],
        ])->assertStatus(409);

        // Nothing survives: no document, no stock, no cash.
        $this->assertSame($returnsBefore, SaleReturn::query()->count());
        $this->assertSame($movementsBefore, CashRegisterTransaction::query()->count());
        $this->assertSame($stockBefore, $this->stockOf($product));
        $this->assertSame($balanceBefore, $this->balanceOf($register));
    }

    // ------------------------------------------------------------------
    // Tenancy and authorization
    // ------------------------------------------------------------------

    /** @test */
    public function a_sale_of_another_business_cannot_be_returned(): void
    {
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // A fully privileged user of the *other* tenant. Any 404 below is
        // therefore about isolation, not about a missing permission — and it
        // comes from `SaleReturnService` resolving the sale through
        // `forBusiness()`, never from the route model binding.
        $other = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->grantAllCapabilities($other);
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(404);

        $this->assertSame(0, SaleReturn::query()->where('business_id', $this->business->id)->count());
        $this->assertCount(0, $this->allRefunds());
    }

    /** @test */
    public function a_return_of_another_business_cannot_be_read(): void
    {
        $foreignSale = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $foreign = SaleReturn::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'sale_id' => $foreignSale->id,
        ]);

        $this->getJson("/api/v1/sale-returns/{$foreign->id}")->assertStatus(404);
        $this->assertSame(0, $this->getJson('/api/v1/sale-returns')->json('data.pagination.total'));
    }

    /** @test */
    public function a_user_without_the_return_capability_is_refused(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // Can read sales and move cash, but holds no return capability at all.
        $user = User::factory()->create(['business_id' => $this->business->id]);
        $this->grantRoleWith($user, [
            PermissionService::SALES_VIEW,
            PermissionService::CASH_REGISTERS_VIEW,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(403);

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->allRefunds());
    }

    /** @test */
    public function an_unauthenticated_return_is_refused(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => 1,
            'items' => [['sale_item_id' => 1, 'quantity' => 1]],
        ])->assertStatus(401);

        $this->assertSame(0, SaleReturn::query()->count());
    }

    /** @test */
    public function a_suspended_user_cannot_record_a_return(): void
    {
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);

        // Suspending the account behind a token that already exists: the
        // `active` middleware must still refuse it, exactly as it refuses every
        // other protected route.
        $this->user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(401);

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->allRefunds());
    }

    /** @test */
    public function the_return_list_and_detail_stay_scoped_to_this_business(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->cashSale($product);
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 2));

        $this->assertSame(1, $this->getJson('/api/v1/sale-returns')->json('data.pagination.total'));
        $this->getJson("/api/v1/sale-returns/{$ret['id']}")
            ->assertStatus(200)
            ->assertJsonPath('data.returned_cost', '120.00');
    }
}
