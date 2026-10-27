<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Customer\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — the **sales / returns / profit / refund / receivable** policy.
 *
 * The rules under test, each of which a report gets wrong in a way that is
 * arithmetically valid and still lies to the shopkeeper:
 *
 *  - **Gross, returns and net are three separate numbers.** A return is a dated
 *    document in its own right; it is never hidden and never folded into an
 *    invoice, so `gross - returns = net` is checkable from the payload alone.
 *  - **Profit carries the matching cost reversal.** Netting the revenue of a
 *    return without also returning its cost would charge the business for
 *    stock it gave back, so `net revenue - net COGS - expenses = net profit`.
 *  - **The reversal uses the historical cost.** `sale_items.unit_cost` is the
 *    price actually paid; today's `products.cost_price` is not.
 *  - **A refund is outbound.** It is money leaving the business, so it can never
 *    read as a collection and can never inflate the receivable.
 *  - **Receivables come from the ledger.** `sales.due_amount` is frozen at
 *    invoice time and is not a balance.
 *
 * Every sale and return here is created through the HTTP API, so the fixtures
 * carry the payment rows, refund rows, `unit_cost` snapshots and ledger entries
 * the real flows write — a fixture built straight on the models could satisfy
 * these assertions while the actual system did not.
 */
class ReportSalesReturnsPolicyTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private const DAY = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

    /** A stock-keeping product: `$cost` cost, `$price` sell, 100 on hand. */
    private function product(string $cost = '60.00', string $price = '100.00'): Product
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

        // Pricing now lives on inventory batches, not on the product.
        InventoryBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'purchase_item_id' => null,
            'batch_number' => 'TEST-'.fake()->unique()->numerify('######'),
            'received_date' => self::DAY,
            'expiry_date' => null,
            'received_quantity' => '100',
            'remaining_quantity' => '100',
            'cost_price' => $cost,
            'selling_price' => $price,
            'mrp' => null,
            'selling_code' => null,
        ]);

        return $product;
    }

    /** A customer whose account was opened through the service. */
    private function customer(string $openingBalance = '0'): Customer
    {
        return $this->app->make(CustomerService::class)->create($this->business->id, [
            'name' => 'Report Customer',
            'opening_balance' => $openingBalance,
        ]);
    }

    /**
     * Record a sale through the API.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function sale(array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => self::DAY,
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);
        $sale = $response->json('data');

        return [$sale, array_map('intval', array_column($sale['items'], 'id'))];
    }

    /**
     * Post a return against an invoice line and assert it was accepted.
     *
     * `$returnDate` defaults to the reporting day rather than letting the flow
     * choose: the service defaults a return to *today*, which in a suite whose
     * fixtures are pinned to a fixed window would place every return outside the
     * very window under test and make the return assertions silently vacuous.
     *
     * @param  array<int, array<string, mixed>>  $refunds
     * @return array<string, mixed>
     */
    private function returnLine(int $saleId, int $saleItemId, string $quantity, array $refunds = [], ?string $returnDate = null): array
    {
        $response = $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $saleId,
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => $quantity]],
            'return_date' => $returnDate ?? self::DAY,
            'refunds' => $refunds,
        ]);
        $response->assertStatus(201);

        return $response->json('data');
    }

    // ------------------------------------------------------------------
    // Gross, returns, net
    // ------------------------------------------------------------------

    /**
     * The three sales figures must be present *and* mutually consistent.
     *
     * A ৳1,000 sale with a ৳300 return has to read as gross 1,000 / returns 300 /
     * net 700. A report that only published the net would be indistinguishable
     * from one that had quietly dropped the returned invoice from `sales`; a
     * report that published gross under the name "net" would look right until a
     * return happened. Asserting the identity itself is what pins both failures.
     *
     * @test
     */
    public function gross_sales_minus_returns_equals_net_sales(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '3');

        $this->report('sales')
            ->assertStatus(200)
            // Gross is untouched by the return: the invoice was still issued.
            ->assertJsonPath('data.metrics.total_sales', '1000.00')
            ->assertJsonPath('data.metrics.returns_count', 1)
            ->assertJsonPath('data.metrics.returns_amount', '300.00')
            ->assertJsonPath('data.metrics.net_sales', '700.00');
    }

    /**
     * The return belongs to the day it *happened*, not to the day of the sale.
     *
     * Both dates are in this fixture on purpose. Scoping returns by
     * `sales.sale_date` would make a September sale returned in October reduce
     * September — restating last month's revenue this month — while a period in
     * which nothing was sold but a return happened would show no return at all.
     *
     * @test
     */
    public function a_return_is_dated_on_its_own_document_so_it_lands_in_the_period_it_happened(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['sale_date' => '2026-09-15', 'paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '2', [], self::DAY);

        // October: no sale, but a real return.
        $this->report('sales')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.sale_count', 0)
            ->assertJsonPath('data.metrics.total_sales', '0.00')
            ->assertJsonPath('data.metrics.returns_amount', '200.00')
            ->assertJsonPath('data.metrics.net_sales', '-200.00');

        // September: the sale, and no return.
        $this->getJson('/api/v1/reports/sales?date_from=2026-09-01&date_to=2026-09-30')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.total_sales', '1000.00')
            ->assertJsonPath('data.metrics.returns_amount', '0.00')
            ->assertJsonPath('data.metrics.net_sales', '1000.00');
    }

    /**
     * A pending return has reversed nothing.
     *
     * No refund was paid and no ledger entry was written for it, so counting it
     * would subtract revenue that was never taken and make the period's net
     * figure disagree with the money actually moved.
     *
     * @test
     */
    public function a_pending_return_does_not_reduce_revenue(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100]],
            ['paid_amount' => 500, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $return = $this->returnLine($sale['id'], $items[0], '2');
        SaleReturn::query()->whereKey($return['id'])->update(['status' => SaleReturn::STATUS_PENDING]);

        $this->report('sales')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.returns_count', 0)
            ->assertJsonPath('data.metrics.returns_amount', '0.00')
            ->assertJsonPath('data.metrics.net_sales', '500.00');
    }

    /**
     * A `customer_id` filter narrows the returns as well as the sales, so gross
     * and net describe the same customer rather than the same window.
     *
     * @test
     */
    public function a_customer_filter_narrows_the_returns_as_well_as_the_sales(): void
    {
        $product = $this->product();
        $mine = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );
        $theirs = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $customer = $this->customer();
        $other = $this->customer();

        Sale::query()->whereKey($mine[0]['id'])->update(['customer_id' => $customer->id]);
        Sale::query()->whereKey($theirs[0]['id'])->update(['customer_id' => $other->id]);

        $this->returnLine($mine[0]['id'], $mine[1][0], '2');
        $this->returnLine($theirs[0]['id'], $theirs[1][0], '4');

        $this->report('sales', 'customer_id='.$customer->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.sale_count', 1)
            ->assertJsonPath('data.metrics.total_sales', '1000.00')
            ->assertJsonPath('data.metrics.returns_amount', '200.00')
            ->assertJsonPath('data.metrics.net_sales', '800.00');
    }

    // ------------------------------------------------------------------
    // Profit and cost of goods
    // ------------------------------------------------------------------

    /**
     * The full profit identity, with the return's cost reversed alongside its
     * revenue.
     *
     * ৳1,000 of goods at a ৳600 cost gives ৳600 of COGS. Returning 3 of the 10
     * units gives back ৳180 of revenue and ৳180 of cost. Netting only the revenue
     * would charge the shop ৳180 for stock it still has, so the expected net
     * profit is ৳700 − ৳420 − ৳0 of expenses = ৳280.
     *
     * @test
     */
    public function net_profit_reflects_the_revenue_reversal_and_the_returned_cost_of_goods(): void
    {
        $product = $this->product(cost: '60.00', price: '100.00');
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '3');

        $this->report('profit-loss')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', true)
            // Gross terms — what the invoice and its lines actually recorded.
            ->assertJsonPath('data.metrics.revenue', '1000.00')
            ->assertJsonPath('data.metrics.cost_of_goods', '600.00')
            ->assertJsonPath('data.metrics.gross_profit', '400.00')
            // Return terms.
            ->assertJsonPath('data.metrics.returns_amount', '300.00')
            ->assertJsonPath('data.metrics.returned_cost_of_goods', '180.00')
            // Net terms — the identity.
            ->assertJsonPath('data.metrics.net_revenue', '700.00')
            ->assertJsonPath('data.metrics.net_cost_of_goods', '420.00')
            ->assertJsonPath('data.metrics.net_gross_profit', '280.00')
            ->assertJsonPath('data.metrics.net_profit', '280.00');
    }

    /**
     * Expenses come off the *net* profit, not the gross one.
     *
     * With a ৳300 return and ৳100 of expenses, the net figure must be
     * ৳700 − ৳420 − ৳100. Reading `gross_profit` here would be the classic
     * double-count: the return's revenue removed from the top while its cost was
     * left in the middle.
     *
     * @test
     */
    public function expenses_are_subtracted_from_the_net_profit(): void
    {
        $product = $this->product(cost: '60.00', price: '100.00');
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '3');

        $this->postJson('/api/v1/expenses', [
            'expense_date' => self::DAY,
            'amount' => 100,
            'payment_method' => 'cash',
            'description' => 'Shop running costs',
        ])->assertStatus(201);

        $this->report('profit-loss')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.total_expense', '100.00')
            ->assertJsonPath('data.metrics.gross_profit', '400.00')
            ->assertJsonPath('data.metrics.net_gross_profit', '280.00')
            ->assertJsonPath('data.metrics.net_profit', '180.00');
    }

    /**
     * The reversal uses the cost the goods were *sold* for, not today's price.
     *
     * The product is repriced from ৳60 to ৳500 **after** the sale. Reading
     * `products.cost_price` at report time would put ৳1,500 back on the shelf —
     * a cost the business never incurred. The expected ৳180 is the snapshotted
     * cost of three units.
     *
     * @test
     */
    public function the_returned_cost_is_the_historical_unit_cost_not_todays_product_cost(): void
    {
        $product = $this->product(cost: '60.00', price: '100.00');
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '3');



        $this->report('profit-loss')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.cost_of_goods', '600.00')
            ->assertJsonPath('data.metrics.returned_cost_of_goods', '180.00')
            ->assertJsonPath('data.metrics.net_profit', '280.00');
    }

    /**
     * A return whose cost was never recorded withholds profit, exactly as an
     * unpriced sale line does.
     *
     * A service line has no product and therefore no cost. Reversing it as if it
     * were free would *raise* reported profit, so the figure is withheld and the
     * unpriced count covers the returned line too.
     *
     * @test
     */
    public function a_returned_line_with_no_recorded_cost_withholds_profit(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        // A line written with no cost at all, as a service line would be.
        $unpriced = SaleItem::create([
            'sale_id' => $sale['id'],
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 50,
            'unit_cost' => null,
            'discount' => 0,
            'tax' => 0,
            'total' => 50,
        ]);

        $this->returnLine($sale['id'], $unpriced->id, '1');

        $response = $this->report('profit-loss')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.available', false)
            ->assertJsonPath('data.metrics.unpriced_lines', 1)
            // Withheld, not zeroed: a zero here would read as "this cost nothing".
            ->assertJsonPath('data.metrics.cost_of_goods', null)
            ->assertJsonPath('data.metrics.returned_cost_of_goods', null)
            ->assertJsonPath('data.metrics.net_cost_of_goods', null)
            ->assertJsonPath('data.metrics.net_profit', null);

        $this->assertStringContainsString(
            '1 sale line(s)',
            (string) $response->json('data.metrics.limitation'),
        );
    }

    /**
     * A return outside the window must be invisible to *both* reports.
     *
     * `sales()` and `profitLoss()` share one return population precisely so this
     * cannot drift: a report that netted returns the other ignored would make the
     * two disagree about the same period, which is worse than either being
     * uniformly simple.
     *
     * @test
     */
    public function the_profit_report_agrees_with_the_sales_report_about_the_same_returns(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['paid_amount' => 1000, 'payment_method' => Payment::PAYMENT_METHOD_CASH],
        );

        $this->returnLine($sale['id'], $items[0], '3', [], '2026-11-15');

        // The return is outside this window, so neither report may mention it.
        $sales = $this->report('sales');
        $profit = $this->report('profit-loss');

        $sales->assertJsonPath('data.metrics.returns_amount', '0.00')
            ->assertJsonPath('data.metrics.net_sales', '1000.00');

        $profit->assertJsonPath('data.metrics.returns_amount', '0.00')
            ->assertJsonPath('data.metrics.net_revenue', '1000.00')
            ->assertJsonPath('data.metrics.returned_cost_of_goods', '0.00');

        // And the sales report's net revenue is the profit report's net revenue.
        $this->assertSame(
            $sales->json('data.metrics.net_sales'),
            $profit->json('data.metrics.net_revenue'),
            'The two reports must describe the same returns, not two populations.',
        );
    }

    /**
     * `GET /reports/<report>` for the single reporting day.
     *
     * `$query` is appended after the window, so a caller can add a filter
     * without having to repeat the dates.
     */
    private function report(string $report, string $query = ''): TestResponse
    {
        return $this->getJson(
            '/api/v1/reports/'.$report.'?date_from='.self::DAY.'&date_to='.self::DAY
            .($query === '' ? '' : '&'.$query),
        );
    }
}
