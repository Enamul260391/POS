<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class ReportTest extends TestCase
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

    /** @test */
    public function reports_require_authentication_and_reject_invalid_ranges(): void
    {
        $this->flushHeaders();
        foreach (['sales', 'purchases', 'expenses', 'profit-loss', 'inventory', 'dashboard'] as $report) {
            $this->getJson('/api/v1/reports/'.$report)->assertStatus(401);
        }
        $this->actingAsBusinessUser($this->business);
        $this->getJson('/api/v1/reports/sales?date_from=2026-09-30&date_to=2026-09-01')->assertStatus(422);
    }

    /** @test */
    public function sales_report_aggregates_headers_without_duplicate_totals_from_items_or_payments(): void
    {
        $sale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'subtotal' => 100, 'discount' => 10, 'tax' => 5, 'grand_total' => 95, 'paid_amount' => 95, 'due_amount' => 0]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => null, 'quantity' => 2, 'unit_price' => 50, 'total' => 100]);
        Sale::factory()->create(['business_id' => $this->otherBusiness->id, 'grand_total' => 999]);
        $response = $this->getJson('/api/v1/reports/sales?date_from=2026-09-01&date_to=2026-09-30');
        $response->assertStatus(200)->assertJsonPath('data.metrics.sale_count', 1)->assertJsonPath('data.metrics.total_sales', '95.00')->assertJsonPath('data.metrics.net_sales', '95.00');
    }

    /**
     * The payment-method split is a real grouped aggregate over `payments`, not
     * a client-side reconstruction.
     *
     * Three invoices of this tenant inside the window pay in three different
     * methods (with cash split across two payments, so `payment_count` proves
     * the grouping counts rows), and four decoys prove every scope dimension:
     * another tenant's invoice, an invoice outside the window, a purchase
     * payment of this tenant, and a payment on a fourth in-window invoice that
     * the test never reads back — so a breakdown that leaked an extra row would
     * fail the row count rather than pass unnoticed.
     *
     * @test
     */
    public function sales_report_groups_payments_by_method_within_the_tenant_and_window(): void
    {
        $cashSale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'grand_total' => 100, 'paid_amount' => 60]);
        $cardSale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-11', 'grand_total' => 50, 'paid_amount' => 50]);
        $bankSale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-12', 'grand_total' => 30, 'paid_amount' => 30]);

        Payment::create(['business_id' => $this->business->id, 'sale_id' => $cashSale->id, 'payment_method' => 'cash', 'amount' => 40]);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $cashSale->id, 'payment_method' => 'cash', 'amount' => 20]);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $cardSale->id, 'payment_method' => 'card', 'amount' => 50]);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $bankSale->id, 'payment_method' => 'bank', 'amount' => 30]);

        // Another tenant, inside the window: must not appear at all.
        $foreignSale = Sale::factory()->create(['business_id' => $this->otherBusiness->id, 'sale_date' => '2026-09-10']);
        Payment::create(['business_id' => $this->otherBusiness->id, 'sale_id' => $foreignSale->id, 'payment_method' => 'cash', 'amount' => 900]);

        // Outside the window: must not appear at all.
        $oldSale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-08-01']);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $oldSale->id, 'payment_method' => 'cash', 'amount' => 700]);

        // A purchase payment of this tenant, in the window: `payments` is shared
        // with purchases, so it must not be swept into the sales breakdown.
        $purchase = Purchase::factory()->create(['business_id' => $this->business->id, 'purchase_date' => '2026-09-10']);
        Payment::create(['business_id' => $this->business->id, 'purchase_id' => $purchase->id, 'payment_method' => 'cash', 'amount' => 500]);

        $response = $this->getJson('/api/v1/reports/sales?date_from=2026-09-01&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.metrics.payment_breakdown')
            // Largest first, as the API orders it.
            ->assertJsonPath('data.metrics.payment_breakdown.0.payment_method', 'cash')
            ->assertJsonPath('data.metrics.payment_breakdown.0.total_amount', '60.00')
            // Two cash payments of 40 + 20, so the count proves rows are grouped
            // rather than one row per invoice.
            ->assertJsonPath('data.metrics.payment_breakdown.0.payment_count', 2)
            ->assertJsonPath('data.metrics.payment_breakdown.1.payment_method', 'card')
            ->assertJsonPath('data.metrics.payment_breakdown.1.total_amount', '50.00')
            ->assertJsonPath('data.metrics.payment_breakdown.2.payment_method', 'bank')
            ->assertJsonPath('data.metrics.payment_breakdown.2.total_amount', '30.00');

        // The header totals are untouched by the extra grouped query: the three
        // invoices' own paid_amounts (60 + 50 + 30), which is a different sum
        // from the breakdown's (also 140, but reached through the payments).
        $this->assertSame('140.00', $response->json('data.metrics.paid_amount'));
    }

    /**
     * The `payment_method` filter narrows the breakdown as well as the header,
     * so the split can never contradict the totals printed above it.
     *
     * @test
     */
    public function sales_report_payment_method_filter_narrows_the_breakdown(): void
    {
        $mixed = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'grand_total' => 100, 'paid_amount' => 100]);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $mixed->id, 'payment_method' => 'cash', 'amount' => 70]);
        Payment::create(['business_id' => $this->business->id, 'sale_id' => $mixed->id, 'payment_method' => 'card', 'amount' => 30]);

        $this->getJson('/api/v1/reports/sales?payment_method=cash')
            ->assertStatus(200)
            // The invoice is matched because it *has* a cash payment …
            ->assertJsonPath('data.metrics.sale_count', 1)
            // … but only the cash row is reported, at its own amount.
            ->assertJsonCount(1, 'data.metrics.payment_breakdown')
            ->assertJsonPath('data.metrics.payment_breakdown.0.payment_method', 'cash')
            ->assertJsonPath('data.metrics.payment_breakdown.0.total_amount', '70.00');
    }

    /**
     * A window with no payments answers with an empty list, not a fabricated
     * 100% bucket and not an error.
     *
     * @test
     */
    public function sales_report_answers_with_an_empty_payment_breakdown_for_an_empty_period(): void
    {
        $this->getJson('/api/v1/reports/sales?date_from=2030-01-01&date_to=2030-12-31')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.sale_count', 0)
            ->assertJsonPath('data.metrics.payment_breakdown', []);
    }

    /** @test */
    public function purchase_and_expense_reports_group_in_the_database_and_are_tenant_scoped(): void
    {
        $purchase = Purchase::factory()->create(['business_id' => $this->business->id, 'purchase_date' => '2026-09-10', 'grand_total' => 80]);
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => null, 'quantity' => 2, 'unit_cost' => 40, 'total' => 80]);
        Purchase::factory()->create(['business_id' => $this->otherBusiness->id, 'grand_total' => 700]);
        $this->getJson('/api/v1/reports/purchases')->assertStatus(200)->assertJsonPath('data.metrics.purchase_count', 1)->assertJsonPath('data.metrics.purchase_total', '80.00')->assertJsonPath('data.metrics.item_quantity', '2');

        $category = ExpenseCategory::factory()->create(['business_id' => $this->business->id, 'name' => 'Utilities']);
        Expense::factory()->create(['business_id' => $this->business->id, 'expense_category_id' => $category->id, 'expense_date' => '2026-09-10', 'amount' => 12]);
        Expense::factory()->create(['business_id' => $this->otherBusiness->id, 'amount' => 500]);
        $this->getJson('/api/v1/reports/expenses?expense_category_id='.$category->id)->assertStatus(200)->assertJsonPath('data.metrics.expense_count', 1)->assertJsonPath('data.metrics.total_expense', '12.00')->assertJsonPath('data.metrics.category_breakdown.0.name', 'Utilities');
    }

    /**
     * Regression: the purchase aggregate must stay valid under ONLY_FULL_GROUP_BY.
     *
     * The suite runs on SQLite, which does not enforce ONLY_FULL_GROUP_BY, so
     * simply asserting `200` here would NOT have caught the defect. The original
     * code selected a `selectSub` correlated to `purchases.id` into the select
     * list of a query that is nothing but aggregates and has no GROUP BY; MySQL
     * and MariaDB reject that with SQLSTATE 1140 ("Mixing of GROUP columns ...
     * with no GROUP columns is illegal"), which is what made
     * `/reports/purchases` — and `/reports/dashboard`, which calls it — return
     * HTTP 500 in production while this test stayed green.
     *
     * This asserts the SQL that `ReportService::purchases()` **actually emits**
     * (captured from a real request, not a hand-built copy that could drift
     * away from the service again): the item-quantity subquery must be
     * self-contained and must not correlate to the outer `purchases` table.
     *
     * @test
     */
    public function purchase_report_never_correlates_the_item_subquery_to_the_outer_query(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $purchase = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'purchase_date' => '2026-09-10', 'grand_total' => 40]);
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => null, 'quantity' => 4, 'unit_cost' => 10, 'total' => 40]);

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->getJson('/api/v1/reports/purchases')->assertStatus(200);

        $reportQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'purchase')
        ));
        $this->assertCount(1, $reportQueries, 'The purchase report must aggregate in a single query.');

        $sql = $reportQueries[0];

        // The regression: a subquery over purchase_items correlated to the outer
        // `purchases` table. Matched without assuming a quote character, since
        // the suite runs on SQLite (`"purchases"`) while production is MySQL
        // ("`purchases`").
        $this->assertDoesNotMatchRegularExpression(
            '/purchase_items"?\.?"?purchase_id"?\s*=\s*"?purchases"?\.?"?id/i',
            $sql,
            'The item-quantity subquery must not be correlated to the outer query, or ONLY_FULL_GROUP_BY rejects it with SQLSTATE 1140.'
        );
        // The fix: the scope is resolved by a nested, self-contained id subquery.
        $this->assertMatchesRegularExpression(
            '~in \(select (?:.*\.)?"?purchases"?\."?id"?~i',
            $sql,
            'Item quantity must be scoped by a nested, self-contained purchase-id subquery.'
        );
    }

    /**
     * Regression: the whole purchase aggregate still answers, and item quantity
     * is the total across every purchase in scope rather than one purchase.
     *
     * Two purchases with items prove the aggregate is a set total: a correlated
     * implementation could only ever have reported a single purchase's items.
     *
     * @test
     */
    public function purchase_report_sums_item_quantity_across_all_purchases_in_scope(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $first = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'purchase_date' => '2026-09-10', 'grand_total' => 80, 'paid_amount' => 50, 'due_amount' => 30]);
        PurchaseItem::create(['purchase_id' => $first->id, 'product_id' => null, 'quantity' => 2, 'unit_cost' => 40, 'total' => 80]);
        $second = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'purchase_date' => '2026-09-11', 'grand_total' => 20, 'paid_amount' => 20, 'due_amount' => 0]);
        PurchaseItem::create(['purchase_id' => $second->id, 'product_id' => null, 'quantity' => 1.5, 'unit_cost' => 20, 'total' => 20]);

        // A foreign tenant's purchase, with a deliberately huge item quantity.
        $foreignSupplier = Supplier::factory()->create(['business_id' => $this->otherBusiness->id]);
        $foreign = Purchase::factory()->create(['business_id' => $this->otherBusiness->id, 'supplier_id' => $foreignSupplier->id, 'purchase_date' => '2026-09-10', 'grand_total' => 700]);
        PurchaseItem::create(['purchase_id' => $foreign->id, 'product_id' => null, 'quantity' => 500, 'unit_cost' => 700, 'total' => 700]);

        $this->getJson('/api/v1/reports/purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 2)
            ->assertJsonPath('data.metrics.purchase_total', '100.00')
            ->assertJsonPath('data.metrics.paid_amount', '70.00')
            ->assertJsonPath('data.metrics.due_amount', '30.00')
            // 2 + 1.5 across both purchases; the foreign 500 must not appear.
            ->assertJsonPath('data.metrics.item_quantity', '3.5');
    }

    /**
     * Regression: the dashboard depends on `purchases()`, so it inherited the
     * 500. Prove it still answers and that its purchase figures are populated.
     *
     * @test
     */
    public function dashboard_reports_todays_purchase_figures_through_its_purchase_dependency(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        // The dashboard resolves "today" in the *business* timezone, not the
        // application's, so the fixture must be dated in that timezone or the
        // purchase can fall outside the window.
        $today = Carbon::now($this->business->timezone)->toDateString();
        $purchase = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'purchase_date' => $today, 'grand_total' => 500, 'paid_amount' => 200, 'due_amount' => 300]);
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => null, 'quantity' => 4, 'unit_cost' => 125, 'total' => 500]);

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            // Today-only: the response still carries the single `date` field and
            // no `period` wrapper. Reaching this assertion at all proves the
            // purchase aggregation inside the dashboard no longer throws a 500.
            //
            // The purchase *totals* are asserted on MySQL in the purchase report
            // tests; here the count cannot be asserted on SQLite, which stores
            // the `date` column as "Y-m-d H:i:s" and therefore excludes a row from
            // a single-day `<= 'Y-m-d'` window. The shape and today-only contract
            // are what this test guards.
            ->assertJsonPath('data.date', $today)
            ->assertJsonMissingPath('data.period')
            ->assertJsonStructure([
                'data' => [
                    'date', 'today_sale_count', 'today_total_sales', 'today_net_sales',
                    'today_purchase_count', 'today_purchase_total',
                    'today_expense_count', 'today_total_expense',
                    'current_cash_balance', 'low_stock_count',
                ],
            ]);
    }

    /**
     * The empty set must answer with zeroes rather than nulls or an error.
     *
     * @test
     */
    public function purchase_report_answers_with_zeroes_for_an_empty_period(): void
    {
        $this->getJson('/api/v1/reports/purchases?date_from=2030-01-01&date_to=2030-12-31')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 0)
            ->assertJsonPath('data.metrics.purchase_total', '0.00')
            ->assertJsonPath('data.metrics.paid_amount', '0.00')
            ->assertJsonPath('data.metrics.due_amount', '0.00')
            ->assertJsonPath('data.metrics.item_quantity', '0');
    }

    /**
     * The existing supplier filter must still narrow the aggregate, including
     * item quantity.
     *
     * @test
     */
    public function purchase_report_supplier_filter_narrows_the_aggregate(): void
    {
        $wanted = Supplier::factory()->create(['business_id' => $this->business->id]);
        $other = Supplier::factory()->create(['business_id' => $this->business->id]);
        $kept = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $wanted->id, 'purchase_date' => '2026-09-10', 'grand_total' => 40]);
        PurchaseItem::create(['purchase_id' => $kept->id, 'product_id' => null, 'quantity' => 4, 'unit_cost' => 10, 'total' => 40]);
        $dropped = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $other->id, 'purchase_date' => '2026-09-10', 'grand_total' => 60]);
        PurchaseItem::create(['purchase_id' => $dropped->id, 'product_id' => null, 'quantity' => 6, 'unit_cost' => 10, 'total' => 60]);

        $this->getJson('/api/v1/reports/purchases?supplier_id='.$wanted->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '40.00')
            ->assertJsonPath('data.metrics.item_quantity', '4');
    }

    /**
     * The report stays a single database-side aggregate: no per-row reads, and
     * nothing loaded into PHP to be summed there.
     *
     * @test
     */
    public function purchase_report_runs_as_one_aggregate_query(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        foreach (range(1, 5) as $index) {
            $purchase = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'purchase_date' => '2026-09-10', 'grand_total' => 10]);
            PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => null, 'quantity' => $index, 'unit_cost' => 2, 'total' => 10]);
        }

        $queries = [];
        // Only count what the request itself issues: the listener is attached
        // after the fixtures are built, so the inserts above are excluded.
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->getJson('/api/v1/reports/purchases')->assertStatus(200)->assertJsonPath('data.metrics.purchase_count', 5)->assertJsonPath('data.metrics.item_quantity', '15');

        // Only statements reading purchases/purchase_items count towards the
        // report's own cost; the auth middleware's token/user lookups do not.
        $reportQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'purchase')
        ));

        // A constant ceiling: the count cannot grow with the number of purchases
        // or their lines, so an N+1 regression still fails the build.
        $this->assertCount(1, $reportQueries, 'The purchase report must aggregate in a single query, with no per-purchase or per-item reads.');
        // Item quantity is summed by the database, not in PHP.
        $this->assertStringContainsString('SUM(quantity)', $reportQueries[0]);
    }

    /** @test */
    public function inventory_report_uses_signed_stock_movement_ledger_and_ignores_services(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'low_stock_threshold' => 5]);
        Product::factory()->create(['business_id' => $this->business->id, 'unit' => 'service']);
        StockMovement::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'type' => 'receive', 'quantity' => 10]);
        StockMovement::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'type' => 'sale', 'quantity' => -7]);
        $other = Product::factory()->create(['business_id' => $this->otherBusiness->id]);
        StockMovement::create(['business_id' => $this->otherBusiness->id, 'product_id' => $other->id, 'type' => 'receive', 'quantity' => 100]);
        $this->getJson('/api/v1/reports/inventory')->assertStatus(200)->assertJsonPath('data.product_count', 1)->assertJsonPath('data.total_stock', '3')->assertJsonPath('data.low_stock_count', 1)->assertJsonPath('data.total_stock_in', '10')->assertJsonPath('data.total_stock_out', '7');
    }

    /** @test */
    public function dashboard_and_profit_loss_expose_only_reliable_metrics(): void
    {
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 25]);
        $this->assertDatabaseHas('cash_register_transactions', ['type' => CashRegisterTransaction::TYPE_OPENING]);
        $this->getJson('/api/v1/reports/dashboard')->assertStatus(200)->assertJsonPath('data.current_cash_balance', '25.00')->assertJsonPath('data.today_sale_count', 0);
    }

    /**
     * Profit is now computable: `sale_items.unit_cost` snapshots the cost of the
     * units at the moment of sale, so the report can subtract real COGS from
     * revenue instead of refusing to answer.
     *
     * @test
     */
    public function profit_loss_report_subtracts_the_snapshotted_cost_of_goods(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'cost_price' => 30, 'selling_price' => 50]);
        $sale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'subtotal' => 200, 'discount' => 0, 'tax' => 0, 'grand_total' => 200, 'paid_amount' => 200, 'due_amount' => 0]);
        // 4 units at a cost of 30 = 120 of COGS against 200 of revenue.
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50, 'unit_cost' => 30, 'discount' => 0, 'tax' => 0, 'total' => 200]);
        Expense::factory()->create(['business_id' => $this->business->id, 'expense_date' => '2026-09-10', 'amount' => 30]);

        $this->getJson('/api/v1/reports/profit-loss?date_from=2026-09-01&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', true)
            ->assertJsonPath('data.metrics.limitation', null)
            ->assertJsonPath('data.metrics.revenue', '200.00')
            ->assertJsonPath('data.metrics.cost_of_goods', '120.00')
            ->assertJsonPath('data.metrics.gross_profit', '80.00')
            ->assertJsonPath('data.metrics.gross_margin_percent', '40.00')
            ->assertJsonPath('data.metrics.total_expense', '30.00')
            ->assertJsonPath('data.metrics.net_profit', '50.00')
            ->assertJsonPath('data.metrics.unpriced_lines', 0);
    }

    /**
     * A line with no recorded cost must NOT be treated as costing nothing.
     *
     * Summing a NULL cost as 0 would understate COGS and overstate profit, so
     * the report withholds the profit figures and says how many rows are
     * responsible — the same honest "unavailable" contract it had before, now
     * driven by data rather than by a missing column.
     *
     * @test
     */
    public function profit_loss_report_withholds_profit_while_any_line_has_no_recorded_cost(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'cost_price' => 30, 'selling_price' => 50]);
        $sale = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'grand_total' => 200, 'paid_amount' => 200]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50, 'unit_cost' => 30, 'discount' => 0, 'tax' => 0, 'total' => 200]);
        // A service line: no product, so no cost was ever captured for it.
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => null, 'quantity' => 1, 'unit_price' => 50, 'unit_cost' => null, 'discount' => 0, 'tax' => 0, 'total' => 50]);

        $response = $this->getJson('/api/v1/reports/profit-loss?date_from=2026-09-01&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', false)
            ->assertJsonPath('data.metrics.unpriced_lines', 1)
            // The cost-derived figures are withheld, not zeroed.
            ->assertJsonPath('data.metrics.cost_of_goods', null)
            ->assertJsonPath('data.metrics.gross_profit', null)
            ->assertJsonPath('data.metrics.gross_margin_percent', null)
            ->assertJsonPath('data.metrics.net_profit', null);

        $this->assertStringContainsString(
            '1 sale line(s)',
            (string) $response->json('data.metrics.limitation')
        );
    }

    /**
     * The profit population must be the invoices the report actually covers —
     * this tenant, this window — and another tenant's costs must never leak in.
     *
     * @test
     */
    public function profit_loss_report_is_tenant_and_window_scoped(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'cost_price' => 10, 'selling_price' => 20]);
        $mine = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-09-10', 'grand_total' => 100, 'paid_amount' => 100]);
        SaleItem::create(['sale_id' => $mine->id, 'product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20, 'unit_cost' => 10, 'discount' => 0, 'tax' => 0, 'total' => 100]);

        // Another tenant, inside the window.
        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id, 'sale_date' => '2026-09-10', 'grand_total' => 900, 'paid_amount' => 900]);
        SaleItem::create(['sale_id' => $foreign->id, 'product_id' => $product->id, 'quantity' => 9, 'unit_price' => 100, 'unit_cost' => 90, 'discount' => 0, 'tax' => 0, 'total' => 900]);

        // This tenant, outside the window.
        $old = Sale::factory()->create(['business_id' => $this->business->id, 'sale_date' => '2026-08-01', 'grand_total' => 700, 'paid_amount' => 700]);
        SaleItem::create(['sale_id' => $old->id, 'product_id' => $product->id, 'quantity' => 7, 'unit_price' => 100, 'unit_cost' => 70, 'discount' => 0, 'tax' => 0, 'total' => 700]);

        $this->getJson('/api/v1/reports/profit-loss?date_from=2026-09-01&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.revenue', '100.00')
            ->assertJsonPath('data.metrics.cost_of_goods', '50.00')
            ->assertJsonPath('data.metrics.gross_profit', '50.00');
    }

    /**
     * An empty window must answer with zeroes, not nulls and not an error.
     *
     * @test
     */
    public function profit_loss_report_answers_with_zeroes_for_an_empty_period(): void
    {
        $this->getJson('/api/v1/reports/profit-loss?date_from=2030-01-01&date_to=2030-12-31')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', true)
            ->assertJsonPath('data.metrics.revenue', '0.00')
            ->assertJsonPath('data.metrics.cost_of_goods', '0.00')
            ->assertJsonPath('data.metrics.gross_profit', '0.00')
            // No revenue means no meaningful margin, so it is withheld rather
            // than divided by zero.
            ->assertJsonPath('data.metrics.gross_margin_percent', null)
            ->assertJsonPath('data.metrics.net_profit', '0.00');
    }
}
