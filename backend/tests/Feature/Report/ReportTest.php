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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
