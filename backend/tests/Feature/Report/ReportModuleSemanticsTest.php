<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — the **existing semantics** of the purchase, expense, inventory and
 * dashboard reports.
 *
 * These four are documented and locked rather than changed. Each already works,
 * each has a decision embedded in it that nobody would guess from the payload
 * alone, and each has a gap that a later stage will have to address. Writing the
 * decision down as a test is what stops a future stage "fixing" it by accident.
 *
 * The gap in each case is asserted too — not as a failure, but as the current,
 * deliberate answer.
 */
class ReportModuleSemanticsTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-10-01';

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

    /** `GET /reports/<report>` for the single reporting day. */
    private function report(string $report, string $query = ''): TestResponse
    {
        return $this->getJson(
            '/api/v1/reports/'.$report.'?date_from='.self::DAY.'&date_to='.self::DAY
            .($query === '' ? '' : '&'.$query),
        );
    }

    /** An expense category of this business. */
    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(
            ['business_id' => $this->business->id, 'name' => $name],
            ['description' => 'Report fixture category.'],
        );
    }

    /** An expense, optionally dated and categorised. */
    private function expense(
        string $amount,
        string $date = self::DAY,
        ?ExpenseCategory $category = null,
        string $paymentMethod = Expense::PAYMENT_METHOD_CASH,
    ): Expense {
        return Expense::factory()->create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category?->id,
            'expense_date' => $date,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
        ]);
    }

    /**
     * A purchase of `$total` for the given supplier on the reporting day.
     */
    private function purchase(
        string $total,
        ?Supplier $supplier = null,
        string $quantity = '2',
        ?string $date = null,
    ): Purchase {
        $purchase = Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => ($supplier ?? Supplier::factory()->create(['business_id' => $this->business->id]))->id,
            'purchase_date' => $date ?? self::DAY,
            'grand_total' => $total,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => $quantity,
            'unit_cost' => $total,
            'total' => $total,
        ]);

        return $purchase;
    }
    // ------------------------------------------------------------------
    // Purchases
    // ------------------------------------------------------------------

    /**
     * Draft purchases are included, because there is no other status to include.
     *
     * `PurchaseService::create()` is the only writer of a purchase row and it
     * always writes `STATUS_DRAFT`; there is no "complete" transition anywhere in
     * the system. A report that filtered drafts out would therefore be
     * permanently empty, so the absence of a status filter is load-bearing rather
     * than an oversight. If a lifecycle is ever added, this test is the thing that
     * has to change with it.
     *
     * @test
     */
    public function the_purchase_report_includes_draft_purchases(): void
    {
        $this->purchase('500');

        $this->assertSame(
            Purchase::STATUS_DRAFT,
            Purchase::query()->firstOrFail()->status,
            'The fixture must be the only status the system actually produces.',
        );

        $this->report('purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '500.00');
    }

    /**
     * Money comes from the purchase header, and the header agrees with itself.
     *
     * `paid_amount` / `due_amount` are columns on `purchases`, not sums over
     * `payments`. `PurchaseService` writes both on create, so `due = total - paid`
     * holds by construction — and a later payment against the purchase would
     * revise the columns but is not something this report re-derives.
     *
     * @test
     */
    public function purchase_money_is_read_from_the_header_columns(): void
    {
        $purchase = $this->purchase('800');
        $purchase->forceFill(['paid_amount' => '300', 'due_amount' => '500'])->save();

        // A payment row exists but has no `purchase_id`: `PurchaseService` writes
        // no `Payment` of its own, so purchase payments are not represented
        // independently anywhere.
        Payment::create([
            'business_id' => $this->business->id,
            'payment_method' => Payment::PAYMENT_METHOD_BANK,
            'amount' => 300,
        ]);

        $this->report('purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.paid_amount', '300.00')
            ->assertJsonPath('data.metrics.due_amount', '500.00')
            ->assertJsonPath('data.metrics.purchase_total', '800.00');

        // The report has no payment split to give, and this is the documented gap
        // rather than a missing feature discovered here.
        $payload = $this->report('purchases')->assertStatus(200)->json('data.metrics');

        $this->assertArrayNotHasKey(
            'payment_breakdown',
            $payload,
            'Purchase payments are not independently reported; the header columns are the only source.',
        );
    }

    /**
     * The supplier filter is an exact match, and other suppliers drop out entirely.
     *
     * @test
     */
    public function the_supplier_filter_is_an_exact_match(): void
    {
        $wanted = Supplier::factory()->create(['business_id' => $this->business->id]);
        $other = Supplier::factory()->create(['business_id' => $this->business->id]);

        $this->purchase('500', $wanted, '2');
        $this->purchase('900', $other, '5');

        $this->report('purchases', 'supplier_id='.$wanted->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '500.00')
            ->assertJsonPath('data.metrics.item_quantity', '2');
    }

    /**
     * Item quantity comes from `purchase_items`, summed across the window.
     *
     * @test
     */
    public function purchase_item_quantity_is_summed_from_the_line_items(): void
    {
        $this->purchase('100', quantity: '2.5');
        $this->purchase('200', quantity: '1.5');

        $this->report('purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 2)
            ->assertJsonPath('data.metrics.purchase_total', '300.00')
            // 2.5 + 1.5, on the ledger's four-digit scale.
            ->assertJsonPath('data.metrics.item_quantity', '4');
    }

    /**
     * The window is `purchase_date`, and both ends of it are inclusive days.
     *
     * A purchase on the day before the window and one on the day after bracket
     * the window exactly, so a `<=`-that-truncates or an off-by-one on either
     * bound would show up here as a count of two or of zero.
     *
     * @test
     */
    public function the_purchase_window_is_purchase_date_and_includes_the_whole_day(): void
    {
        $before = $this->purchase('100');
        $inside = $this->purchase('200');
        $after = $this->purchase('300');

        $before->forceFill(['purchase_date' => '2026-09-30'])->save();
        $after->forceFill(['purchase_date' => '2026-10-02'])->save();

        $this->report('purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '200.00')
            ->assertJsonPath('data.period.from', self::DAY)
            ->assertJsonPath('data.period.to', self::DAY);

        // The day before is fully in its own window, closing day included.
        $this->getJson('/api/v1/reports/purchases?date_from=2026-09-30&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '100.00');

        $this->getJson('/api/v1/reports/purchases?date_from=2026-09-30&date_to=2026-10-02')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 3);
    }
    // ------------------------------------------------------------------
    // Expenses
    // ------------------------------------------------------------------

    /**
     * The total is the plain sum of `expenses.amount` in the window.
     *
     * Expenses have no lifecycle — there is no draft status — so no status filter
     * applies and every expense in the window counts.
     *
     * @test
     */
    public function the_expense_total_is_the_sum_of_the_window(): void
    {
        $this->expense('100.50');
        $this->expense('49.25');
        $this->expense('9999', '2026-09-30');

        $this->report('expenses')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.expense_count', 2)
            ->assertJsonPath('data.metrics.total_expense', '149.75');
    }

    /**
     * An expense with no category forms its own bucket rather than being dropped.
     *
     * `expense_category_id` is nullable, so a bucket has to exist for the rows
     * that have no category — otherwise the breakdown would silently stop adding
     * up to `total_expense`.
     *
     * @test
     */
    public function an_expense_with_no_category_forms_its_own_bucket(): void
    {
        $this->expense('100', self::DAY, $this->category('Rent'));
        $this->expense('50', self::DAY, null);

        $report = $this->report('expenses')->assertStatus(200);

        $this->assertSame('150.00', $report->json('data.metrics.total_expense'));

        $names = array_column($report->json('data.metrics.category_breakdown'), 'name');

        $this->assertContains('Rent', $names);
        $this->assertContains('Uncategorized', $names);

        // Both buckets together account for the whole total.
        $bucketed = array_sum(array_map(
            static fn (array $row): float => (float) $row['total_expense'],
            $report->json('data.metrics.category_breakdown'),
        ));

        $this->assertEqualsWithDelta(150.00, $bucketed, 0.001);
    }

    /**
     * Payment method is stored on every expense but is **not** reported.
     *
     * The column is written and validated against `Expense::PAYMENT_METHODS`, so
     * the data exists and could be grouped safely — but the report groups by
     * category only. Adding the grouping is a Reports *feature* for a later stage,
     * not a policy correction, so it is deliberately absent here and its absence
     * is asserted so a later stage adds it deliberately rather than by accident.
     *
     * @test
     */
    public function expense_payment_method_is_stored_but_not_yet_reported(): void
    {
        $this->expense('100', self::DAY, $this->category('Rent'), Expense::PAYMENT_METHOD_CASH);
        $this->expense('100', self::DAY, $this->category('Rent'), Expense::PAYMENT_METHOD_BANK);

        $report = $this->report('expenses')->assertStatus(200);

        // The data really is there…
        $this->assertSame(
            ['cash', 'bank'],
            Expense::query()->orderBy('id')->pluck('payment_method')->all(),
            'The write path must still record a payment method.',
        );

        // …and the report does not pretend to have a breakdown it cannot support.
        $this->assertArrayNotHasKey('payment_method_breakdown', $report->json('data.metrics'));
        $this->assertSame('200.00', $report->json('data.metrics.total_expense'));
    }

    /**
     * The category filter narrows the total as well as the breakdown.
     *
     * @test
     */
    public function the_category_filter_narrows_both_the_total_and_the_breakdown(): void
    {
        $rent = $this->category('Rent');
        $this->expense('100', self::DAY, $rent);
        $this->expense('250', self::DAY, $this->category('Wages'));

        $this->report('expenses', 'expense_category_id='.$rent->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.expense_count', 1)
            ->assertJsonPath('data.metrics.total_expense', '100.00')
            ->assertJsonPath('data.metrics.category_breakdown.0.name', 'Rent');
    }

    /**
     * Another tenant's category cannot be used as a filter or supply a name.
     *
     * @test
     */
    public function another_businesss_category_is_rejected_as_a_filter(): void
    {
        $foreign = ExpenseCategory::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->getJson('/api/v1/reports/expenses?expense_category_id='.$foreign->id)
            ->assertStatus(422)
            ->assertJsonPath('errors.expense_category_id.0', 'The selected expense category id is invalid.');
    }
    // ------------------------------------------------------------------
    // Inventory
    // ------------------------------------------------------------------

    /** A product id with 100 units already on hand. */
    private function stockedProduct(): int
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'low_stock_threshold' => 0,
        ]);
        $this->movement($product, '100');

        return (int) $product->getKey();
    }

    /** One signed stock movement, written straight to the ledger. */
    private function movement(Product $product, string $quantity): void
    {
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->getKey(),
            'type' => str_starts_with($quantity, '-')
                ? StockMovement::TYPE_REMOVE
                : StockMovement::TYPE_RECEIVE,
            'quantity' => $quantity,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Quantity comes from the signed movement ledger, and nothing else.
     *
     * There is no `stock` column to fall back on and no separate valuation
     * figure: `total_stock` is `SUM(stock_movements.quantity)`, and `source` says
     * so on the payload.
     *
     * @test
     */
    public function inventory_quantity_is_the_signed_movement_ledger_and_nothing_else(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $untouched = Product::factory()->create(['business_id' => $this->business->id]);

        $this->movement($product, '100');
        $this->movement($product, '-30');
        $this->movement($untouched, '500');

        $this->report('inventory')
            ->assertStatus(200)
            ->assertJsonPath('data.source', 'stock_movements')
            ->assertJsonPath('data.product_count', 2)
            // 100 - 30 across every product; the untouched one is simply 0.
            ->assertJsonPath('data.total_stock', '570')
            ->assertJsonPath('data.stock_movement_count', 3)
            ->assertJsonPath('data.total_stock_in', '600')
            ->assertJsonPath('data.total_stock_out', '30');
    }

    /**
     * The inventory report publishes **no valuation**, because the schema cannot
     * support an honest one.
     *
     * `stock_movements` records no cost, so the only way to put a money figure on
     * the units on hand would be to re-read `products.cost_price` — which is
     * *today's* price and would misvalue every unit bought at a different one.
     * The same error that would corrupt a historical profit figure. Asserting the
     * key's absence is how a later stage adds valuation deliberately, with a cost
     * source, rather than by accident.
     *
     * @test
     */
    public function inventory_publishes_no_stock_valuation(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $this->movement($product, '10');

        $payload = $this->report('inventory')->assertStatus(200)->json('data');

        foreach ([
            'total_stock_value',
            'stock_value',
            'total_cost',
            'inventory_value',
            'valuation',
        ] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $payload,
                "Inventory must not publish {$forbidden}: the ledger records no cost to value it from.",
            );
        }

        $this->assertSame('10', $payload['total_stock'], 'Only quantity is reported, and it is exact.');
    }

    /**
     * The movement totals are cumulative over the whole ledger, not the window.
     *
     * A movement dated long before the requested window is still counted, because
     * on-hand quantity is a current reading and a movement is never un-written.
     * This is the honest behaviour for a snapshot, and the reason the report has
     * no `period` block at all.
     *
     * @test
     */
    public function inventory_movement_totals_are_cumulative_not_windowed(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->getKey(),
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '80',
            'created_by' => $this->user->id,
            'created_at' => '2020-01-01 00:00:00',
        ]);
        $this->movement($product, '20');

        $this->report('inventory')
            ->assertStatus(200)
            ->assertJsonPath('data.stock_movement_count', 2)
            ->assertJsonPath('data.total_stock_in', '100');

        // No period block, because there is no period.
        $this->assertArrayNotHasKey('period', $this->report('inventory')->json('data'));
    }

    /**
     * A service is not stock and never counts towards low stock.
     *
     * @test
     */
    public function services_are_excluded_from_inventory_and_low_stock(): void
    {
        $low = Product::factory()->create([
            'business_id' => $this->business->id,
            'low_stock_threshold' => 5,
        ]);
        $this->movement($low, '2');

        Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => Product::UNIT_SERVICE,
        ]);

        $this->report('inventory')
            ->assertStatus(200)
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.total_stock', '2')
            ->assertJsonPath('data.low_stock_count', 1);
    }
    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    /**
     * The dashboard answers for today in the **business** timezone, whatever the
     * request asked for.
     *
     * Today-only by construction: a "today" screen that silently honoured a
     * different range would be lying about its own title. The dates are still
     * validated, so a malformed request is a 422 rather than a silent no-op.
     *
     * @test
     */
    public function the_dashboard_is_today_only_and_ignores_the_requested_range(): void
    {
        $today = now($this->business->timezone)->toDateString();
        $product = $this->stockedProduct();

        $this->postJson('/api/v1/sales', [
            'sale_date' => $today,
            'items' => [['product_id' => $product, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(201);

        $this->postJson('/api/v1/sales', [
            'sale_date' => '2020-01-01',
            'items' => [['product_id' => $product, 'quantity' => 1, 'unit_price' => 500]],
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/reports/dashboard?date_from=2020-01-01&date_to=2020-01-02')
            ->assertStatus(200);

        $this->assertSame($today, $response->json('data.date'));
        $this->assertSame(1, $response->json('data.today_sale_count'));
        $this->assertSame('100.00', $response->json('data.today_total_sales'));

        // A malformed range is still refused rather than quietly ignored.
        $this->getJson('/api/v1/reports/dashboard?date_from=2026-09-30&date_to=2026-09-01')
            ->assertStatus(422);
    }

    /**
     * `today_net_sales` inherits the gross-minus-returns reading.
     *
     * @test
     */
    public function the_dashboards_net_sales_is_net_of_returns(): void
    {
        $today = now($this->business->timezone)->toDateString();

        $sale = $this->postJson('/api/v1/sales', [
            'sale_date' => $today,
            'items' => [['product_id' => $this->stockedProduct(), 'quantity' => 10, 'unit_price' => 100]],
            'paid_amount' => 1000,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ])->assertStatus(201)->json('data');

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'return_date' => $today,
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3]],
        ])->assertStatus(201);

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.today_total_sales', '1000.00')
            ->assertJsonPath('data.today_returns_amount', '300.00')
            ->assertJsonPath('data.today_net_sales', '700.00');
    }

    /**
     * Expenses and purchases on the dashboard are the same one-day readings.
     *
     * @test
     */
    public function the_dashboard_expenses_and_purchases_cover_today_only(): void
    {
        $today = now($this->business->timezone)->toDateString();

        $this->expense('120', $today, $this->category('Rent'));
        $this->expense('999', '2020-01-01', $this->category('Wages'));

        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->purchase('500', $supplier, '2', $today);

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.date', $today)
            ->assertJsonPath('data.today_expense_count', 1)
            ->assertJsonPath('data.today_total_expense', '120.00')
            ->assertJsonPath('data.today_purchase_count', 1)
            ->assertJsonPath('data.today_purchase_total', '500.00');
    }

    /**
     * Low stock is the inventory report's figure, passed straight through.
     *
     * @test
     */
    public function the_dashboard_low_stock_count_is_the_inventory_reading(): void
    {
        $low = Product::factory()->create([
            'business_id' => $this->business->id,
            'low_stock_threshold' => 5,
        ]);
        $this->movement($low, '1');

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.low_stock_count', 1);
    }

    /**
     * The dashboard carries no receivable or due figure at all.
     *
     * Recorded as the current answer rather than an oversight: a receivable is a
     * point-in-time reading and this screen is scoped to a single day, so folding
     * a balance into it would put two different questions on one card. A later
     * stage can add one deliberately.
     *
     * @test
     */
    public function the_dashboard_publishes_no_receivable_figure(): void
    {
        $payload = $this->getJson('/api/v1/reports/dashboard')->assertStatus(200)->json('data');

        foreach (['customer_outstanding', 'total_due', 'receivable', 'outstanding'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $payload,
                "The dashboard is a one-day screen and must not carry a point-in-time balance ({$forbidden}).",
            );
        }
    }
}
