<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 27 — query-count regression guards.
 *
 * These tests assert no timings. Wall-clock numbers are machine dependent and
 * would be noise; what is asserted is the *shape* of the query pattern — the
 * number of database round trips a request makes, and that it does not grow
 * with the number of lines in the request.
 *
 * That shape is exactly what regressed in Phase 27:
 *  - a cash-register read ran a byte-identical `SUM(...)` aggregate twice;
 *  - creating a return ran one `select * from products` per returned line;
 *  - checking out a basket ran one `SUM(quantity)` per distinct product.
 *
 * Counts are asserted as a fixed ceiling rather than an exact number, so
 * ordinary future work (one more eager load) does not break the build while a
 * return to N+1 still does.
 */
class QueryCountTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    /** @var array<int, string> */
    private array $sql = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
    }

    /**
     * Run a request while recording its SELECT statements.
     *
     * Writes are excluded on purpose: this suite guards read amplification.
     *
     * @return array<int, string>
     */
    private function selectQueriesDuring(callable $request): array
    {
        $this->sql = [];
        $this->recording = true;
        DB::listen(function ($query): void {
            if ($this->recording && str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $this->sql[] = $query->sql;
            }
        });

        try {
            $request();
        } finally {
            $this->recording = false;
        }

        return $this->sql;
    }

    private function productWithStock(User $user, string $quantity = '50'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'selling_price' => '100.00',
        ]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => $quantity,
            'created_by' => $user->id,
        ]);

        return $product;
    }

    /** @param array<int, string> $queries */
    private function countMatching(array $queries, string $needle): int
    {
        return count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, $needle),
        ));
    }

    /** @test */
    public function a_cash_register_read_runs_the_ledger_aggregate_only_once(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $register = CashRegister::create([
            'business_id' => $this->business->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_balance' => '100.00',
            'status' => CashRegister::STATUS_OPEN,
        ]);
        CashRegisterTransaction::create([
            'business_id' => $this->business->id,
            'cash_register_id' => $register->id,
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => '25.00',
            'created_by' => $user->id,
        ]);

        $queries = $this->selectQueriesDuring(
            fn () => $this->getJson("/api/v1/cash-registers/{$register->id}")->assertOk(),
        );

        // The inflow/outflow SUM is the statement that was previously repeated.
        $this->assertSame(
            1,
            $this->countMatching($queries, 'as total_in'),
            'A register read must aggregate its transactions exactly once: '.json_encode($queries),
        );
    }

    /** @test */
    public function a_cash_register_summary_does_not_repeat_the_lifetime_aggregate(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $register = CashRegister::create([
            'business_id' => $this->business->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_balance' => '100.00',
            'status' => CashRegister::STATUS_OPEN,
        ]);

        $queries = $this->selectQueriesDuring(
            fn () => $this->getJson("/api/v1/cash-registers/{$register->id}/summary")->assertOk(),
        );

        // One unfiltered lifetime aggregate (from presenting the register) plus
        // one date-windowed aggregate. The unfiltered one must not be issued a
        // second time just to fill `current_balance`.
        $this->assertLessThanOrEqual(
            2,
            $this->countMatching($queries, 'as total_in'),
            'The summary must not re-run an aggregate it already has: '.json_encode($queries),
        );
    }

    /** @test */
    public function creating_a_return_does_not_query_products_once_per_line(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $lines = [];
        for ($i = 0; $i < 4; $i++) {
            $product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '100.00']);
            StockMovement::create([
                'business_id' => $this->business->id, 'product_id' => $product->id,
                'type' => StockMovement::TYPE_RECEIVE, 'quantity' => '50', 'created_by' => $user->id,
            ]);
            $lines[] = ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100];
        }

        $saleId = $this->postJson('/api/v1/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => $lines,
        ])->assertStatus(201)->json('data.id');

        $itemIds = Sale::find($saleId)->saleItems()->orderBy('id')->pluck('id')->all();
        $this->assertCount(4, $itemIds);

        $queries = $this->selectQueriesDuring(fn () => $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $saleId,
            'items' => array_map(static fn ($id): array => ['sale_item_id' => $id, 'quantity' => 1], $itemIds),
        ])->assertStatus(201));

        // The N+1 was `select * from "products" where "products"."id" = ?`
        // — a bound single-id lookup issued once per returned line.
        $singleProductLookups = count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "products"')
                && ! str_contains($sql, 'in (')
                && ! str_contains($sql, 'in ?'),
        ));
        $this->assertSame(
            0,
            $singleProductLookups,
            'Returning 4 lines must not issue 4 single-row product lookups: '.json_encode($queries),
        );
    }

    /**
     * Phase 27: the checkout stock guard now reads balances through one grouped
     * aggregate instead of one query per product. This proves that a failure on
     * a *later* line still rolls the whole basket back — the guard runs before
     * any write, so nothing may survive, including the lines that were fine.
     *
     * @test
     */
    public function a_later_insufficient_line_rolls_back_the_whole_basket(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $plenty = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '10.00']);
        $scarce = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '10.00']);
        StockMovement::create([
            'business_id' => $this->business->id, 'product_id' => $plenty->id,
            'type' => StockMovement::TYPE_RECEIVE, 'quantity' => '100', 'created_by' => $user->id,
        ]);
        StockMovement::create([
            'business_id' => $this->business->id, 'product_id' => $scarce->id,
            'type' => StockMovement::TYPE_RECEIVE, 'quantity' => '1', 'created_by' => $user->id,
        ]);

        $movementsBefore = StockMovement::count();

        $this->postJson('/api/v1/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $plenty->id, 'quantity' => 5, 'unit_price' => 10],
                ['product_id' => $scarce->id, 'quantity' => 50, 'unit_price' => 10],
            ],
        ])->assertStatus(409);

        // Nothing at all may be persisted: not the header, not the good line.
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    /** @test */
    public function checking_out_many_lines_reads_stock_balances_in_one_aggregate(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $lines = [];
        for ($i = 0; $i < 6; $i++) {
            $product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '10.00']);
            StockMovement::create([
                'business_id' => $this->business->id, 'product_id' => $product->id,
                'type' => StockMovement::TYPE_RECEIVE, 'quantity' => '50', 'created_by' => $user->id,
            ]);
            $lines[] = ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10];
        }

        $queries = $this->selectQueriesDuring(fn () => $this->postJson('/api/v1/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => $lines,
        ])->assertStatus(201));

        // A grouped `GROUP BY product_id` read is one query for any number of
        // products. The pre-fix pattern was one `sum("quantity")` per product.
        $this->assertSame(
            1,
            $this->countMatching($queries, 'SUM(quantity)'),
            'A 6-line basket must read every balance in one aggregate: '.json_encode($queries),
        );
    }

    /** @test */
    public function a_batched_balance_read_matches_the_per_product_balance(): void
    {
        $service = $this->app->make(InventoryService::class);
        $user = $this->actingAsBusinessUser($this->business);

        $withLedger = $this->productWithStock($user, '12.5');
        $withoutLedger = Product::factory()->create(['business_id' => $this->business->id]);

        $balances = $service->currentStockForProducts($this->business->id, [
            $withLedger->id,
            $withoutLedger->id,
        ]);

        // A product with no movements must read as zero, not be missing.
        // `Quantity::format()` trims trailing zeros, so the canonical form of
        // 12.5 is "12.5" and of zero is "0".
        $this->assertSame('12.5', $balances[$withLedger->id]);
        $this->assertSame('0', $balances[$withoutLedger->id]);

        // Must agree with the single-product reader it replaces.
        $this->assertSame($service->currentStock($this->business->id, $withLedger->id), $balances[$withLedger->id]);
        $this->assertSame($service->currentStock($this->business->id, $withoutLedger->id), $balances[$withoutLedger->id]);
    }

    /** @test */
    public function a_batched_balance_read_is_scoped_to_its_tenant(): void
    {
        $service = $this->app->make(InventoryService::class);
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);

        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);
        StockMovement::create([
            'business_id' => $otherBusiness->id, 'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE, 'quantity' => '999', 'created_by' => $otherUser->id,
        ]);

        // Business A must never observe business B's ledger.
        $balances = $service->currentStockForProducts($this->business->id, [$product->id]);

        $this->assertSame('0', $balances[$product->id]);
    }

    /** @test */
    public function a_batched_balance_read_ignores_duplicate_and_empty_inputs(): void
    {
        $service = $this->app->make(InventoryService::class);
        $user = $this->actingAsBusinessUser($this->business);
        $product = $this->productWithStock($user, '5');

        $this->assertSame([], $service->currentStockForProducts($this->business->id, []));

        $balances = $service->currentStockForProducts($this->business->id, [$product->id, $product->id]);
        $this->assertSame(['5'], array_values($balances));
    }
}
