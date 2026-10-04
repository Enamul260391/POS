<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — **tenant isolation** across the whole Reports suite.
 *
 * Two businesses, each with a complete and deliberately *different* set of figures
 * on the same day, and every report is asked as one business and then as the
 * other. With figures this far apart a leak is unmissable rather than subtle.
 *
 * The tenant is never a parameter the client can supply: `ReportController` takes
 * the business from the bearer token, so every query is scoped by
 * `forBusiness($businessId)` — or, for the child tables that carry no
 * `business_id` of their own, through the parent document's id set.
 *
 * The interesting failure is not a missing scope, which throws; it is a scope
 * *bypassed* by an aggregate over a child table, which is silent.
 * `sale_items`, `purchase_items`, `sale_return_items` and `payments` all lack a
 * `business_id` column, so every one of them is a place a cross-tenant aggregate
 * could be built by accident. All four are exercised below.
 */
class ReportTenantIsolationTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-10-01';

    private Business $mine;

    private Business $theirs;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = Business::factory()->create();
        $this->theirs = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->mine);
    }

    /** A stocked product of the given business. */
    private function product(Business $business, string $cost = '40.00'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'cost_price' => $cost,
            'selling_price' => '100.00',
        ]);

        StockMovement::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '50',
            'created_by' => $this->user->id,
        ]);

        return $product;
    }

    /**
     * A priced, paid invoice with a return against one of its lines.
     *
     * Built on the models rather than through the API so each business's fixture
     * can be given exact figures; the shape written here is exactly the one the
     * HTTP flows write — including a `Payment` for the collection and a
     * `sale_return_id` on the refund.
     *
     * @return array{0: Sale, 1: SaleItem}
     */
    private function invoiceWithReturn(Business $business, string $total, string $returnAmount): array
    {
        $product = $this->product($business);
        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'sale_date' => self::DAY,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_amount' => $total,
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => $product->cost_price,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
        ]);

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'type' => Payment::TYPE_PAYMENT,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
            'amount' => $total,
        ]);

        $saleReturn = SaleReturn::factory()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'return_date' => self::DAY,
            'subtotal' => $returnAmount,
            'refund_amount' => $returnAmount,
            'status' => SaleReturn::STATUS_COMPLETED,
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $saleReturn->id,
            'sale_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $returnAmount,
            'total' => $returnAmount,
        ]);

        Payment::create([
            'business_id' => $business->id,
            // A refund points at the return, never at the sale.
            'sale_id' => null,
            'sale_return_id' => $saleReturn->id,
            'type' => Payment::TYPE_REFUND,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
            'amount' => $returnAmount,
        ]);

        return [$sale, $item];
    }

    /** An expense of the given business, in a category of its own. */
    private function expense(Business $business, string $amount): void
    {
        $category = ExpenseCategory::factory()->create([
            'business_id' => $business->id,
            'name' => 'Category '.$business->id,
        ]);

        Expense::factory()->create([
            'business_id' => $business->id,
            'expense_category_id' => $category->id,
            'expense_date' => self::DAY,
            'amount' => $amount,
        ]);
    }

    /** A purchase of the given business. */
    private function purchase(Business $business, string $total): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);
        $purchase = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplier->id,
            'purchase_date' => self::DAY,
            'grand_total' => $total,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => 2,
            'unit_cost' => $total,
            'total' => $total,
        ]);
    }

    /** A receivable belonging to the given business. */
    private function receivable(Business $business, string $amount): void
    {
        CustomerLedgerEntry::create([
            'business_id' => $business->id,
            'customer_id' => Customer::factory()->create(['business_id' => $business->id])->id,
            'type' => CustomerLedgerEntry::TYPE_SALE_DUE,
            'amount' => $amount,
        ]);
    }

    /** `GET /reports/<report>` for the single reporting day. */
    private function report(string $report): TestResponse
    {
        return $this->getJson('/api/v1/reports/'.$report.'?date_from='.self::DAY.'&date_to='.self::DAY);
    }
    // ------------------------------------------------------------------
    // One assertion per report
    // ------------------------------------------------------------------

    /**
     * Sales: neither the invoice totals, the payment split, nor the receivables.
     *
     * @test
     */
    public function sales_report_shows_only_this_business(): void
    {
        $this->invoiceWithReturn($this->mine, '1000', '300');
        $this->invoiceWithReturn($this->theirs, '7000', '2000');
        $this->receivable($this->mine, '250');
        $this->receivable($this->theirs, '5000');

        $this->report('sales')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.sale_count', 1)
            ->assertJsonPath('data.metrics.total_sales', '1000.00')
            ->assertJsonPath('data.metrics.paid_amount', '1000.00')
            ->assertJsonPath('data.metrics.customer_outstanding', '250.00')
            ->assertJsonPath('data.metrics.payment_breakdown.0.total_amount', '1000.00');
    }

    /**
     * Returns, refunds and the returned cost: the other three places a foreign
     * document could reach.
     *
     * `sale_returns`, `sale_return_items` and refund `payments` each take part
     * here, so a leak in any one of them moves a figure.
     *
     * @test
     */
    public function returns_and_refunds_show_only_this_business(): void
    {
        $this->invoiceWithReturn($this->mine, '1000', '300');
        $this->invoiceWithReturn($this->theirs, '7000', '2000');

        $this->report('sales')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.returns_count', 1)
            ->assertJsonPath('data.metrics.returns_amount', '300.00')
            ->assertJsonPath('data.metrics.net_sales', '700.00')
            ->assertJsonPath('data.metrics.refund_count', 1)
            ->assertJsonPath('data.metrics.refund_amount', '300.00')
            ->assertJsonPath('data.metrics.refund_breakdown.0.refund_amount', '300.00');
    }

    /**
     * Profit: the cost half, aggregated over `sale_items`, is the one most at
     * risk, because that table carries no `business_id` of its own.
     *
     * @test
     */
    public function profit_loss_shows_only_this_business(): void
    {
        $this->invoiceWithReturn($this->mine, '1000', '300');
        $this->invoiceWithReturn($this->theirs, '7000', '2000');

        $this->report('profit-loss')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', true)
            ->assertJsonPath('data.metrics.revenue', '1000.00')
            ->assertJsonPath('data.metrics.cost_of_goods', '40.00')
            ->assertJsonPath('data.metrics.returns_amount', '300.00')
            ->assertJsonPath('data.metrics.returned_cost_of_goods', '40.00')
            ->assertJsonPath('data.metrics.net_revenue', '700.00')
            ->assertJsonPath('data.metrics.net_profit', '700.00');
    }

    /**
     * Expenses, including the category name a foreign category would supply.
     *
     * @test
     */
    public function expenses_report_shows_only_this_business(): void
    {
        $this->expense($this->mine, '120');
        $this->expense($this->theirs, '900');

        $this->report('expenses')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.expense_count', 1)
            ->assertJsonPath('data.metrics.total_expense', '120.00')
            ->assertJsonPath('data.metrics.category_breakdown.0.name', 'Category '.$this->mine->id)
            ->assertJsonPath('data.metrics.category_breakdown.0.total_expense', '120.00');
    }

    /**
     * Purchases, including the item quantity summed over `purchase_items`.
     *
     * @test
     */
    public function purchases_report_shows_only_this_business(): void
    {
        $this->purchase($this->mine, '400');
        $this->purchase($this->theirs, '6000');

        $this->report('purchases')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.purchase_count', 1)
            ->assertJsonPath('data.metrics.purchase_total', '400.00')
            ->assertJsonPath('data.metrics.item_quantity', '2');
    }

    /**
     * Inventory: on-hand quantity and the low-stock count, both from the ledger.
     *
     * @test
     */
    public function inventory_report_shows_only_this_business(): void
    {
        $this->product($this->mine);
        $this->product($this->mine);
        $this->product($this->theirs);

        $this->report('inventory')
            ->assertStatus(200)
            ->assertJsonPath('data.product_count', 2)
            ->assertJsonPath('data.total_stock', '100')
            ->assertJsonPath('data.stock_movement_count', 2)
            ->assertJsonPath('data.low_stock_count', 0);
    }

    /**
     * The dashboard is the same reports over a one-day window, so it inherits
     * their scoping — proven here rather than assumed.
     *
     * The fixtures are re-dated to today because the dashboard's window is fixed
     * to today and ignores the request's dates; that is exactly the behaviour
     * under test.
     *
     * @test
     */
    public function the_dashboard_shows_only_this_business(): void
    {
        $this->invoiceWithReturn($this->mine, '1000', '300');
        $this->invoiceWithReturn($this->theirs, '7000', '2000');
        $this->expense($this->mine, '120');
        $this->expense($this->theirs, '900');
        $this->purchase($this->mine, '400');
        $this->purchase($this->theirs, '6000');

        $this->moveEverythingToToday();

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.today_sale_count', 1)
            ->assertJsonPath('data.today_total_sales', '1000.00')
            ->assertJsonPath('data.today_net_sales', '700.00')
            ->assertJsonPath('data.today_expense_count', 1)
            ->assertJsonPath('data.today_total_expense', '120.00')
            ->assertJsonPath('data.today_purchase_count', 1)
            ->assertJsonPath('data.today_purchase_total', '400.00');
    }

    /**
     * The dashboard's cash balance is the *authenticated user's own* open
     * register, so another business's drawer can never be shown — and a user with
     * no drawer reads `'0.00'` rather than an error.
     *
     * @test
     */
    public function the_dashboard_cash_balance_is_never_another_businesss_drawer(): void
    {
        $theirs = $this->createBusinessUser($this->theirs);

        $register = CashRegister::create([
            'business_id' => $this->theirs->id,
            'opened_by' => $theirs->id,
            'opened_at' => now(),
            'opening_balance' => '5000.00',
            'status' => CashRegister::STATUS_OPEN,
        ]);
        CashRegisterTransaction::create([
            'business_id' => $this->theirs->id,
            'cash_register_id' => $register->id,
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => '5000.00',
            'created_by' => $theirs->id,
        ]);

        // Back to this business's user, who has no drawer of their own.
        $this->actingAsBusinessUser($this->mine);

        $this->getJson('/api/v1/reports/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.current_cash_balance', '0.00');
    }

    /**
     * Re-date every fixture to today, so the dashboard's fixed one-day window
     * covers them.
     *
     * The dashboard deliberately ignores the request's dates; this is the only
     * place in the suite that has to bend to that rather than the other way
     * round.
     */
    private function moveEverythingToToday(): void
    {
        $today = now()->toDateString();

        Sale::query()->update(['sale_date' => $today]);
        SaleReturn::query()->update(['return_date' => $today]);
        Expense::query()->update(['expense_date' => $today]);
        Purchase::query()->update(['purchase_date' => $today]);
    }
}
