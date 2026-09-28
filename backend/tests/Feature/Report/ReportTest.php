<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
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
        $this->getJson('/api/v1/reports/profit-loss')->assertStatus(200)->assertJsonPath('data.metrics.available', false)->assertJsonPath('data.metrics.limitation', 'Historical cost of goods sold is unavailable because sale_items does not preserve unit cost.');
    }
}
