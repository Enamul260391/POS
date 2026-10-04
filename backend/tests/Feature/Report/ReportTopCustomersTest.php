<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 5 — the **sales by customer** ranking added to `GET /reports/sales`.
 *
 * `metrics.top_customers` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **It is the same population, joined sideways rather than down.** It is
 *    grouped off the very builder `sales()` aggregates, so tenant,
 *    `ReportDateRange` window, `customer_id`, `status` and `payment_method`
 *    apply by the same predicates — including the `customer_id` filter, which
 *    narrows the ranking exactly as it narrows `total_sales`.
 *  - **Tenant isolation is stated at every layer.** The invoice scope *and* the
 *    joined customer are each pinned to the caller's business, because
 *    `sales.customer_id` is not itself tenant-scoped and a corrupted row would
 *    otherwise print another business's customer **name** on this tenant's
 *    report. Several tests below reproduce exactly that malformed state.
 *  - **It is gross.** `SUM(sales.grand_total)` — the identical column, and the
 *    identical reading, as `total_sales`. Returns and refunds never reduce a
 *    customer's figure, exactly as they never reduce the total.
 *  - **`invoice_count` counts invoices.** `COUNT(sales.id)`, grouped on the
 *    `sales` table itself, so a multi-line or multi-payment invoice still counts
 *    once and neither `sale_items` nor `payments` can inflate it.
 *  - **Walk-in sales are excluded, and the total is not changed to compensate.**
 *    A null `customer_id` is a real sale in `total_sales` and there is no
 *    customer to rank it under; the schema stores no reserved "Walk-in Customer"
 *    row, so none is invented. The ranking therefore does *not* reconcile to
 *    `total_sales`, which is asserted here as behaviour rather than tolerated.
 *  - **Ranking is by revenue**, ties break on `customer_id` ascending, and the
 *    top N cut is a database `LIMIT` — never a PHP sort or a PHP trim.
 *  - **Money stays a decimal string** with two decimals, through the project's
 *    own `Money::format`, exactly as every other figure on the payload.
 *  - **The cost is one grouped aggregate**, whatever the period length or the size
 *    of the customer book — proven by inspecting the SQL, not just by row count.
 *  - **No new permission and no new schema.** `reports.view` alone still gates
 *    the whole report, and the tables this joins are ones the project already had.
 */
class ReportTopCustomersTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day every boundary test is written around. */
    private const DAY = '2026-01-15';

    /**
     * How many customers the report publishes.
     *
     * Mirrors `ReportService::TOP_CUSTOMERS_LIMIT`. Asserted rather than assumed:
     * the ranking is a bounded card, and an unbounded one would be the whole
     * customer book dressed up as a dashboard.
     */
    private const LIMIT = 10;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * One invoice of this tenant, with no customer unless one is named.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sale(string $saleDate, string $grandTotal, array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'sale_date' => $saleDate,
            'grand_total' => $grandTotal,
            'paid_amount' => $grandTotal,
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
        ], $attributes));
    }

    /**
     * An invoice belonging to a customer, with its exact gross figure.
     *
     * The invoice's business follows the *customer's* business unless one is
     * given, so a fixture can never accidentally create a "foreign" sale that is
     * really a local one — which is the whole point of the tenant tests below.
     */
    private function customerSale(
        Customer $customer,
        string $saleDate,
        string $grandTotal,
        ?Business $business = null,
    ): Sale {
        return $this->sale($saleDate, $grandTotal, [
            'business_id' => ($business ?? $customer->business_id),
            'customer_id' => $customer->id,
        ]);
    }

    /** A customer, of this tenant unless another business is given. */
    private function customer(string $name = 'Customer', ?Business $business = null): Customer
    {
        return Customer::factory()->create([
            'business_id' => ($business ?? $this->business)->id,
            'name' => $name,
        ]);
    }

    /** A category of this tenant. */
    private function category(string $name, ?Business $business = null): Category
    {
        return Category::factory()->create([
            'business_id' => ($business ?? $this->business)->id,
            'name' => $name,
        ]);
    }

    /** A product of this tenant, with an opening stock movement. */
    private function product(string $name, ?Category $category = null, ?Business $business = null): Product
    {
        $product = Product::factory()->create([
            'business_id' => ($business ?? $this->business)->id,
            'category_id' => $category?->id,
            'name' => $name,
        ]);

        StockMovement::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '1000',
            'created_by' => $this->user->id,
        ]);

        return $product;
    }

    /** A priced sale line of an existing product. */
    private function line(Sale $sale, Product $product, string $quantity, string $total): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'total' => $total,
            'unit_cost' => '60.00',
        ]);
    }

    /** Attach an inbound payment row of the given method to a sale. */
    private function pay(Sale $sale, string $method, string $amount): Payment
    {
        return Payment::create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'type' => Payment::TYPE_PAYMENT,
            'payment_method' => $method,
            'amount' => $amount,
            'received_by' => $this->user->id,
        ]);
    }

    /**
     * A completed return against a sale line, with its refund payment.
     *
     * Built on the models so the fixture can carry exact figures. This is the
     * shape the return flow writes: a `unit_cost` snapshot on the line, a
     * `return_date` of its own, and a refund payment carrying the return's id and
     * a null `sale_id`.
     */
    private function completedReturn(SaleItem $line, string $date, string $refundAmount): SaleReturn
    {
        $sale = $line->sale;

        $return = SaleReturn::create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'return_date' => $date,
            'subtotal' => $refundAmount,
            'tax' => '0.00',
            'refund_amount' => $refundAmount,
            'status' => SaleReturn::STATUS_COMPLETED,
            'created_by' => $this->user->id,
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $line->id,
            'product_id' => $line->product_id,
            'quantity' => '1',
            'unit_price' => '100.00',
            'total' => $refundAmount,
        ]);

        if (Money::greaterThan($refundAmount, '0.00')) {
            Payment::create([
                'business_id' => $sale->business_id,
                'sale_return_id' => $return->id,
                'type' => Payment::TYPE_REFUND,
                'payment_method' => Payment::PAYMENT_METHOD_CASH,
                'amount' => $refundAmount,
                'received_by' => $this->user->id,
            ]);
        }

        return $return;
    }

    // ------------------------------------------------------------------
    // Request + extraction helpers
    // ------------------------------------------------------------------

    /**
     * `GET /reports/sales` for an explicit window.
     *
     * @param  array<string, string>  $query
     */
    private function report(string $from, string $to, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/sales?'.http_build_query(array_merge([
            'date_from' => $from,
            'date_to' => $to,
        ], $query)));
    }

    /** `metrics.top_customers` exactly as the payload carries it. */
    private function topCustomers(TestResponse $response): array
    {
        return $response->json('data.metrics.top_customers') ?? [];
    }

    /**
     * The ranking indexed by customer id, for readable assertions.
     *
     * @return array<int, array{name: string, invoice_count: int, total_sales: string}>
     */
    private function byCustomer(TestResponse $response): array
    {
        $indexed = [];

        foreach ($this->topCustomers($response) as $row) {
            $indexed[$row['customer_id']] = [
                'name' => $row['customer_name'],
                'invoice_count' => $row['invoice_count'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    /** The customer ids, in the order the API returned them. */
    private function order(TestResponse $response): array
    {
        return array_column($this->topCustomers($response), 'customer_id');
    }

    /** The whole ranking summed with bcmath, through the project's money helper. */
    private function summed(TestResponse $response): string
    {
        $total = Money::format(null);

        foreach ($this->topCustomers($response) as $row) {
            $total = Money::add($total, $row['total_sales']);
        }

        return $total;
    }

    /** Number of SELECT statements one report request issues. */
    private function countQueries(Closure $request): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $request();

        $count = count(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_starts_with(strtoupper($entry['query']), 'SELECT'),
        ));

        DB::disableQueryLog();

        return $count;
    }
    // ------------------------------------------------------------------
    // Shape and the single-customer case
    // ------------------------------------------------------------------

    /**
     * The field exists, is an array, and carries exactly the contract's keys.
     *
     * @test
     */
    public function top_customers_is_published_on_the_sales_report(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertIsArray($response->json('data.metrics.top_customers'));
        $this->assertCount(1, $this->topCustomers($response));
        $this->assertSame(
            ['customer_id', 'customer_name', 'invoice_count', 'total_sales'],
            array_keys($this->topCustomers($response)[0]),
        );
    }

    /**
     * The wire types are exactly what the contract promises.
     *
     * A JSON payload has no types, so this is the only place the *shapes* of the
     * four fields can be pinned: an integer id, an integer count, and money as a
     * two-decimal **string** — never a float, which is what
     * `ReportService::sales()` emits everywhere else and what the frontend
     * mapper's `toMoney` expects.
     *
     * @test
     */
    public function every_field_carries_the_declared_wire_type(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $this->customerSale($customer, self::DAY, '4250.00');

        $row = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertIsInt($row['customer_id']);
        $this->assertIsString($row['customer_name']);
        $this->assertIsInt($row['invoice_count']);
        $this->assertIsString($row['total_sales']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $row['total_sales']);
        $this->assertSame('4250.00', $row['total_sales']);
    }

    /**
     * One customer, one invoice: the row is that invoice's own figures.
     *
     * @test
     */
    public function a_single_customer_is_reported_with_its_own_figures(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $this->customerSale($customer, self::DAY, '4250.00');

        $row = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertSame($customer->id, $row['customer_id']);
        $this->assertSame('Ayesha Rahman', $row['customer_name']);
        $this->assertSame(1, $row['invoice_count']);
        $this->assertSame('4250.00', $row['total_sales']);
    }

    /**
     * Distinct customers stay distinct rows — the grouping is real.
     *
     * @test
     */
    public function distinct_customers_are_reported_as_distinct_rows(): void
    {
        $ayesha = $this->customer('Ayesha Rahman');
        $imran = $this->customer('Imran Hossain');

        $this->customerSale($ayesha, self::DAY, '1500.00');
        $this->customerSale($imran, self::DAY, '3800.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->byCustomer($response);

        $this->assertCount(2, $this->topCustomers($response));
        $this->assertSame([$imran->id, $ayesha->id], $this->order($response));
        $this->assertTrue(Money::equals('1500.00', $rows[$ayesha->id]['total_sales']));
        $this->assertTrue(Money::equals('3800.00', $rows[$imran->id]['total_sales']));
    }

    /**
     * One customer's invoices aggregate across the whole period.
     *
     * This is the case a per-invoice implementation gets right on the first
     * invoice and wrong on the second, which is why it is asserted explicitly.
     *
     * @test
     */
    public function a_customer_aggregates_across_several_invoices(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, '2026-01-10', '500.00');
        $this->customerSale($customer, self::DAY, '1250.00');
        $this->customerSale($customer, '2026-02-05', '2500.00');

        $response = $this->report('2026-01-01', '2026-02-28')->assertStatus(200);
        $rows = $this->topCustomers($response);

        // One row, not three: the customer is the grouping key.
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('4250.00', $rows[0]['total_sales']));
        // And it agrees with the report's own total, because every sale here is
        // attributable to a customer.
        $this->assertTrue(Money::equals('4250.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A customer with many invoices is still exactly one row.
     *
     * @test
     */
    public function one_customer_with_many_invoices_aggregates_once(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        for ($index = 1; $index <= 12; $index++) {
            $this->customerSale($customer, self::DAY, '100.00');
        }

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame(12, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('1200.00', $rows[0]['total_sales']));
    }
    // ------------------------------------------------------------------
    // Invoice count is invoices, not lines and not payments
    // ------------------------------------------------------------------

    /**
     * One invoice with four lines is still one invoice.
     *
     * The case that separates `COUNT(sales.id)` from `COUNT(sale_items.id)`: a
     * POS invoice is routinely multi-line, and a leaderboard that counted lines
     * would be ranking basket size, not customer value.
     *
     * @test
     */
    public function an_invoice_with_many_lines_is_counted_once(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $sale = $this->customerSale($customer, self::DAY, '400.00');

        for ($index = 1; $index <= 4; $index++) {
            $this->line($sale, $this->product('Product '.$index), '1', '100.00');
        }

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(1, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('400.00', $rows[0]['total_sales']));
    }

    /**
     * One invoice settled in three payments is still one invoice.
     *
     * The other half of the same argument, and the reason the payment filter
     * cannot leak into the count: `scopedSales()` resolves invoices through
     * `payments` with a `whereHas`, which must not turn into three counted rows.
     *
     * @test
     */
    public function an_invoice_settled_in_several_payments_is_counted_once(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $sale = $this->customerSale($customer, self::DAY, '900.00');

        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '300.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CARD, '300.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '300.00');

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(1, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('900.00', $rows[0]['total_sales']));
    }

    /**
     * Lines and payments together, on two invoices.
     *
     * @test
     */
    public function neither_lines_nor_payments_inflate_the_count(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $first = $this->customerSale($customer, '2026-01-14', '500.00');
        $this->line($first, $this->product('A'), '1', '250.00');
        $this->line($first, $this->product('B'), '1', '250.00');
        $this->pay($first, Payment::PAYMENT_METHOD_CASH, '250.00');
        $this->pay($first, Payment::PAYMENT_METHOD_CARD, '250.00');

        $second = $this->customerSale($customer, '2026-01-15', '700.00');
        $this->line($second, $this->product('C'), '1', '700.00');
        $this->pay($second, Payment::PAYMENT_METHOD_CASH, '700.00');

        $rows = $this->topCustomers($this->report('2026-01-01', '2026-01-31')->assertStatus(200));

        $this->assertSame(2, $rows[0]['invoice_count'], 'two invoices, three lines, three payments');
        $this->assertTrue(Money::equals('1200.00', $rows[0]['total_sales']));
    }

    /**
     * Money formatting holds to two decimals on awkward figures.
     *
     * `Money::format` is what guarantees this, and it matters here because the
     * frontend renders the string directly: a float would print `4250.1` or
     * `0.30000000000000004`.
     *
     * @test
     */
    public function money_is_formatted_to_two_decimals(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, self::DAY, '0.10');
        $this->customerSale($customer, self::DAY, '1234.56');

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('1234.66', $rows[0]['total_sales']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $rows[0]['total_sales']);
    }
    // ------------------------------------------------------------------
    // Ranking, ties and the top-N cut
    // ------------------------------------------------------------------

    /**
     * Revenue decides the order, largest first.
     *
     * @test
     */
    public function customers_are_ranked_by_gross_revenue_descending(): void
    {
        $ayesha = $this->customer('Ayesha Rahman');
        $imran = $this->customer('Imran Hossain');
        $nusrat = $this->customer('Nusrat Jahan');

        // Deliberately created out of rank order, so an implementation that
        // returned rows in insertion order would fail this.
        $this->customerSale($nusrat, self::DAY, '1000.00');
        $this->customerSale($imran, self::DAY, '3800.00');
        $this->customerSale($ayesha, self::DAY, '4250.00');

        $this->assertSame(
            [$ayesha->id, $imran->id, $nusrat->id],
            $this->order($this->report(self::DAY, self::DAY)->assertStatus(200)),
        );
    }

    /**
     * Equal revenue breaks on `customer_id` ascending, deterministically.
     *
     * Without a total tie-break the order of two equal rows is whatever the
     * database felt like returning, so two identical requests could produce two
     * different payloads — and a card that re-renders between them would appear
     * to shuffle rows at random.
     *
     * @test
     */
    public function equal_revenue_breaks_on_customer_id_ascending(): void
    {
        $first = $this->customer('First');
        $second = $this->customer('Second');
        $third = $this->customer('Third');

        // Created and sold in an order that is neither the id order nor the name
        // order, so neither can pass by accident.
        $this->customerSale($third, self::DAY, '1000.00');
        $this->customerSale($first, self::DAY, '1000.00');
        $this->customerSale($second, self::DAY, '1000.00');

        $expected = [$first->id, $second->id, $third->id];
        sort($expected);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame($expected, $this->order($response));

        // And it is stable across repeats, not merely sorted once by luck.
        $this->assertSame(
            $this->order($response),
            $this->order($this->report(self::DAY, self::DAY)->assertStatus(200)),
        );
    }

    /**
     * The cut is at ten rows, and it is the *highest* ten.
     *
     * @test
     */
    public function the_ranking_is_capped_at_ten_customers(): void
    {
        for ($index = 1; $index <= 14; $index++) {
            $this->customerSale($this->customer('Customer '.$index), self::DAY, $index.'.00');
        }

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(self::LIMIT, $rows);
        // 14.00 down to 5.00 — the ten highest, in descending order.
        $this->assertSame(
            ['14.00', '13.00', '12.00', '11.00', '10.00', '9.00', '8.00', '7.00', '6.00', '5.00'],
            array_column($rows, 'total_sales'),
        );
    }

    /**
     * Exactly ten customers come back whole — the cut is not off by one.
     *
     * @test
     */
    public function a_ranking_of_exactly_ten_customers_is_returned_whole(): void
    {
        for ($index = 1; $index <= self::LIMIT; $index++) {
            $this->customerSale($this->customer('Customer '.$index), self::DAY, $index.'.00');
        }

        $this->assertCount(
            self::LIMIT,
            $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200)),
        );
    }

    /**
     * A customer who never bought is not a report row.
     *
     * @test
     */
    public function a_customer_with_no_sales_is_not_ranked(): void
    {
        $bought = $this->customer('Bought Something');
        $this->customer('Never Bought');

        $this->customerSale($bought, self::DAY, '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$bought->id], $this->order($response));
        // The quiet customer still exists as a customer — this is a ranking cut,
        // not a deletion.
        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
    }
    // ------------------------------------------------------------------
    // Gross semantics — returns and refunds do not reduce the figure
    // ------------------------------------------------------------------

    /**
     * A return against a customer's sale does not reduce their gross figure.
     *
     * This is the stage's defining semantic. `total_sales` is gross of returns,
     * so a per-customer figure that subtracted them would disagree with the total
     * printed above it — and would answer a different question (net sales per
     * customer) on a field documented as gross.
     *
     * @test
     */
    public function a_return_does_not_reduce_the_customers_gross_total(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $product = $this->product('Latte');

        $sale = $this->customerSale($customer, self::DAY, '1000.00');
        $line = $this->line($sale, $product, '10', '1000.00');

        // Half the invoice came back, with its cash refund.
        $this->completedReturn($line, self::DAY, '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertTrue(Money::equals('1000.00', $rows[0]['total_sales']), 'gross, not net');
        $this->assertSame(1, $rows[0]['invoice_count']);

        // And the reversal is still visible where it belongs, without having
        // touched the ranking.
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.total_sales')));
        $this->assertTrue(Money::equals('500.00', $response->json('data.metrics.returns_amount')));
        $this->assertTrue(Money::equals('500.00', $response->json('data.metrics.net_sales')));
    }

    /**
     * A refund payment does not reduce the customer's gross total either.
     *
     * A refund is a `Payment` with `type = 'refund'`, a null `sale_id` and a
     * `sale_return_id`. It is money going out, reported on `refund_amount` and
     * netted into `net_collected_amount`; it has no place in a revenue ranking.
     *
     * @test
     */
    public function a_refund_payment_does_not_reduce_the_customers_gross_total(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $product = $this->product('Latte');

        $sale = $this->customerSale($customer, self::DAY, '800.00');
        $line = $this->line($sale, $product, '8', '800.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '800.00');

        $this->completedReturn($line, self::DAY, '800.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertTrue(Money::equals('800.00', $rows[0]['total_sales']));
        $this->assertTrue(Money::equals('800.00', $response->json('data.metrics.refund_amount')));
        // Collection is unaffected by the ranking, and the two agree.
        $this->assertTrue(Money::equals('0.00', $response->json('data.metrics.net_collected_amount')));
    }

    /**
     * An unpaid / partly-due invoice is ranked at its full gross value.
     *
     * The customer ranking is a revenue figure. It does not read `paid_amount`,
     * `due_amount` or the ledger, and a customer who owes money is still a
     * customer who bought something.
     *
     * @test
     */
    public function an_unpaid_invoice_is_ranked_at_its_full_gross_value(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, self::DAY, '500.00');
        $this->sale(self::DAY, '500.00', [
            'customer_id' => $customer->id,
            'paid_amount' => '0.00',
            'due_amount' => '500.00',
            'payment_status' => Sale::PAYMENT_STATUS_PENDING,
        ]);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertSame(2, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('1000.00', $rows[0]['total_sales']));
        $this->assertTrue(Money::equals('500.00', $response->json('data.metrics.due_amount')));
    }

    /**
     * Nothing above the ranking is derived from it.
     *
     * The stage is additive: the ranking reads the sales population, and no
     * existing metric reads the ranking.
     *
     * @test
     */
    public function no_existing_metric_is_derived_from_the_ranking(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $other = $this->customer('Imran Hossain');

        $this->customerSale($customer, self::DAY, '1000.00');
        $this->customerSale($other, self::DAY, '500.00');
        $this->sale(self::DAY, '300.00'); // walk-in

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $this->assertSame(3, $m['sale_count']);
        $this->assertTrue(Money::equals('1800.00', $m['total_sales']));
        $this->assertTrue(Money::equals('1800.00', $m['net_sales']));
        $this->assertTrue(Money::equals('0.00', $m['returns_amount']));
        $this->assertTrue(Money::equals('1800.00', $m['paid_amount']));
        $this->assertTrue(Money::equals('0.00', $m['due_amount']));
        $this->assertTrue(Money::equals('0.00', $m['refund_amount']));
        $this->assertTrue(Money::equals('1800.00', $m['net_collected_amount']));
    }
    // ------------------------------------------------------------------
    // Filters inherited from the shared sales scope
    // ------------------------------------------------------------------

    /**
     * `date_from` is inclusive of its whole day.
     *
     * @test
     */
    public function date_from_is_inclusive(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, '2026-01-14', '100.00');
        $this->customerSale($customer, self::DAY, '200.00');
        $this->customerSale($customer, '2026-01-16', '400.00');

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(1, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('200.00', $rows[0]['total_sales']));
    }

    /**
     * `date_to` is inclusive of its whole day, to the last instant.
     *
     * A sale stamped late on `date_to` is inside the window. This is the boundary
     * `ReportDateRange` exists to get right, and the ranking inherits it rather
     * than re-deriving it.
     *
     * @test
     */
    public function date_to_is_inclusive_of_the_whole_calendar_day(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, '2026-01-14', '100.00');
        // Late on the final day of the window: still inside it.
        $sale = $this->customerSale($customer, self::DAY, '200.00');
        $sale->forceFill(['sale_date' => '2026-01-15 23:59:59'])->save();

        $rows = $this->topCustomers($this->report('2026-01-15', self::DAY)->assertStatus(200));

        $this->assertSame(1, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('200.00', $rows[0]['total_sales']));
    }

    /**
     * The first day after `date_to` is outside it.
     *
     * @test
     */
    public function the_day_after_date_to_is_excluded(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, self::DAY, '200.00');
        $this->customerSale($customer, '2026-01-16', '400.00');

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertTrue(Money::equals('200.00', $rows[0]['total_sales']));
    }

    /**
     * A bounded window ranks only what falls inside it.
     *
     * @test
     */
    public function a_bounded_window_ranks_only_sales_inside_it(): void
    {
        $inside = $this->customer('Inside');
        $outside = $this->customer('Outside');

        $this->customerSale($inside, '2026-01-10', '100.00');
        $this->customerSale($inside, self::DAY, '200.00');
        $this->customerSale($outside, '2026-02-20', '9999.00');

        $response = $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $this->assertSame([$inside->id], $this->order($response));
        $this->assertSame(2, $this->topCustomers($response)[0]['invoice_count']);
        $this->assertTrue(Money::equals('300.00', $this->summed($response)));
    }

    /**
     * The `customer_id` filter narrows the ranking, not just the totals.
     *
     * This is the filter most likely to be silently dropped by an implementation
     * that wrote its own customer-side query, so it is asserted directly: the
     * *other* customer has a larger gross figure and still must not appear.
     *
     * @test
     */
    public function the_customer_filter_narrows_the_ranking(): void
    {
        $wanted = $this->customer('Ayesha Rahman');
        $bigger = $this->customer('Imran Hossain');

        $this->customerSale($wanted, self::DAY, '500.00');
        $this->customerSale($bigger, self::DAY, '9000.00');

        $response = $this->report(self::DAY, self::DAY, ['customer_id' => $wanted->id])
            ->assertStatus(200);

        $this->assertSame([$wanted->id], $this->order($response));
        $this->assertTrue(Money::equals('500.00', $this->topCustomers($response)[0]['total_sales']));
        // The header totals narrow by the same filter, so the two still describe
        // the same slice.
        $this->assertTrue(Money::equals('500.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The `status` filter narrows the ranking.
     *
     * Also the reason `scopedSales()` qualifies its column names: `customers`
     * has a `status` of its own, so an unqualified `where('status', ...)` on this
     * join would be ambiguous and the request would fail outright.
     *
     * @test
     */
    public function the_status_filter_narrows_the_ranking(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, self::DAY, '300.00');
        $this->sale(self::DAY, '700.00', [
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_DRAFT,
        ]);

        $completed = $this->report(self::DAY, self::DAY, ['status' => Sale::STATUS_COMPLETED])
            ->assertStatus(200);
        $draft = $this->report(self::DAY, self::DAY, ['status' => Sale::STATUS_DRAFT])
            ->assertStatus(200);

        $this->assertSame(1, $this->topCustomers($completed)[0]['invoice_count']);
        $this->assertTrue(Money::equals('300.00', $this->topCustomers($completed)[0]['total_sales']));
        $this->assertSame(1, $this->topCustomers($draft)[0]['invoice_count']);
        $this->assertTrue(Money::equals('700.00', $this->topCustomers($draft)[0]['total_sales']));
    }

    /**
     * The `payment_method` filter narrows the ranking.
     *
     * @test
     */
    public function the_payment_method_filter_narrows_the_ranking(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $cash = $this->customerSale($customer, self::DAY, '300.00');
        $this->pay($cash, Payment::PAYMENT_METHOD_CASH, '300.00');

        $card = $this->customerSale($customer, self::DAY, '700.00');
        $this->pay($card, Payment::PAYMENT_METHOD_CARD, '700.00');

        $cashOnly = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CASH])
            ->assertStatus(200);
        $cardOnly = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CARD])
            ->assertStatus(200);

        // One invoice each — the many-payments-per-invoice trap cannot recur
        // here, but the count must still be invoices.
        $this->assertSame(1, $this->topCustomers($cashOnly)[0]['invoice_count']);
        $this->assertTrue(Money::equals('300.00', $this->topCustomers($cashOnly)[0]['total_sales']));
        $this->assertSame(1, $this->topCustomers($cardOnly)[0]['invoice_count']);
        $this->assertTrue(Money::equals('700.00', $this->topCustomers($cardOnly)[0]['total_sales']));
    }

    /**
     * Combined filters apply together, by the same predicates.
     *
     * @test
     */
    public function combined_filters_apply_together(): void
    {
        $wanted = $this->customer('Ayesha Rahman');
        $other = $this->customer('Imran Hossain');

        // In the window, right customer, completed, paid by card.
        $yes = $this->customerSale($wanted, self::DAY, '111.00');
        $this->pay($yes, Payment::PAYMENT_METHOD_CARD, '111.00');

        // Each of these fails exactly one of the four filters.
        $wrongDay = $this->customerSale($wanted, '2026-03-01', '222.00');
        $this->pay($wrongDay, Payment::PAYMENT_METHOD_CARD, '222.00');

        $wrongCustomer = $this->customerSale($other, self::DAY, '333.00');
        $this->pay($wrongCustomer, Payment::PAYMENT_METHOD_CARD, '333.00');

        $wrongMethod = $this->customerSale($wanted, self::DAY, '444.00');
        $this->pay($wrongMethod, Payment::PAYMENT_METHOD_CASH, '444.00');

        $wrongStatus = $this->sale(self::DAY, '555.00', [
            'customer_id' => $wanted->id,
            'status' => Sale::STATUS_DRAFT,
        ]);
        $this->pay($wrongStatus, Payment::PAYMENT_METHOD_CARD, '555.00');

        $response = $this->report('2026-01-01', '2026-01-31', [
            'customer_id' => $wanted->id,
            'status' => Sale::STATUS_COMPLETED,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200);

        $this->assertSame([$wanted->id], $this->order($response));
        $this->assertSame(1, $this->topCustomers($response)[0]['invoice_count']);
        $this->assertTrue(Money::equals('111.00', $this->topCustomers($response)[0]['total_sales']));
        // The header agreed: only the one qualifying invoice is in the totals.
        $this->assertTrue(Money::equals('111.00', $response->json('data.metrics.total_sales')));
        $this->assertSame(1, $response->json('data.metrics.sale_count'));
    }
    // ------------------------------------------------------------------
    // Walk-in sales and empty states
    // ------------------------------------------------------------------

    /**
     * A walk-in sale is excluded from the ranking — and from nothing else.
     *
     * `sales.customer_id` is nullable and a null means an unassigned, walk-in
     * sale. There is no customer to rank it under, and the schema holds no
     * reserved "Walk-in Customer" row, so it is simply absent from the
     * leaderboard while remaining a first-class sale in `total_sales`.
     *
     * @test
     */
    public function a_walk_in_sale_is_excluded_from_the_ranking_but_kept_in_the_total(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, self::DAY, '1000.00');
        $this->sale(self::DAY, '250.00'); // customer_id null: walk-in

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$customer->id], $this->order($response));
        $this->assertTrue(Money::equals('1000.00', $this->topCustomers($response)[0]['total_sales']));

        // The money is still in the report: it was really sold.
        $this->assertSame(2, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('1250.00', $response->json('data.metrics.total_sales')));
        $this->assertTrue(Money::equals('1250.00', $response->json('data.metrics.net_sales')));
    }

    /**
     * No fabricated walk-in row, and no placeholder name anywhere.
     *
     * @test
     */
    public function no_walk_in_placeholder_customer_is_invented(): void
    {
        $this->sale(self::DAY, '250.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertSame([], $rows);
        $this->assertSame(0, Customer::withoutGlobalScopes()->count(), 'no customer row was created');

        $body = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['Walk-in', 'Walk In', 'Unknown', 'Guest', 'Unassigned'] as $placeholder) {
            $this->assertStringNotContainsStringIgnoringCase($placeholder, $body);
        }
    }

    /**
     * Sales exist, but none can be attributed: the ranking is empty, the total is
     * not.
     *
     * This is the second empty state the UI has to be able to tell apart from
     * "the period was quiet", which is why both are asserted side by side here.
     *
     * @test
     */
    public function sales_that_are_all_walk_ins_yield_an_empty_ranking(): void
    {
        $this->sale(self::DAY, '250.00');
        $this->sale(self::DAY, '750.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topCustomers($response));
        $this->assertSame(2, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The ranking is explicitly *not* required to reconcile to `total_sales`.
     *
     * Pinned as behaviour, not tolerated: an implementation that "fixed" this by
     * inventing a walk-in bucket, or by dropping walk-in money from the total,
     * would be wrong in both directions. The gap is real and is the documented
     * consequence of the two different questions the two fields answer.
     *
     * @test
     */
    public function the_ranking_need_not_reconcile_to_total_sales(): void
    {
        $small = $this->customer('Small');
        $large = $this->customer('Large');

        $this->customerSale($large, self::DAY, '9000.00');
        $this->customerSale($small, self::DAY, '1000.00');
        $this->sale(self::DAY, '2000.00'); // walk-in
        $this->sale(self::DAY, '500.00');  // walk-in

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue(Money::equals('12500.00', $response->json('data.metrics.total_sales')));
        $this->assertTrue(Money::equals('10000.00', $this->summed($response)));

        // The two differ by exactly the walk-in money, every time.
        $this->assertTrue(Money::equals(
            '2500.00',
            Money::subtract($response->json('data.metrics.total_sales'), $this->summed($response)),
        ));
    }

    /**
     * A quiet period returns an empty ranking, not an error.
     *
     * @test
     */
    public function zero_matching_sales_returns_an_empty_ranking(): void
    {
        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topCustomers($response));
        $this->assertSame(0, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('0.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Sales exist, but none of them fall in the requested window.
     *
     * @test
     */
    public function sales_outside_the_window_yield_an_empty_ranking(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), '2026-06-01', '500.00');

        $this->assertSame([], $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200)));
    }
    // ------------------------------------------------------------------
    // Soft deletes
    // ------------------------------------------------------------------

    /**
     * A soft-deleted customer drops out of the ranking, by the schema's own
     * lifecycle rule.
     *
     * `Customer` soft-deletes and `sales` stores no customer-name snapshot, so a
     * removed customer's revenue cannot be reported under a name the server can
     * still read. This is the same rule `top_products` and `top_categories`
     * already follow for a deleted product or category — no historical name is
     * invented, and no deletion model is changed to accommodate it.
     *
     * @test
     */
    public function a_soft_deleted_customer_is_not_ranked(): void
    {
        $removed = $this->customer('Removed Customer');
        $kept = $this->customer('Kept Customer');

        $this->customerSale($removed, self::DAY, '4000.00');
        $this->customerSale($kept, self::DAY, '100.00');

        $removed->delete();
        $this->assertSoftDeleted($removed);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertSame([$kept->id], $this->order($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Removed Customer',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        // The invoice total is unaffected: only the ranking excludes it.
        $this->assertTrue(Money::equals('4100.00', $response->json('data.metrics.total_sales')));
        $this->assertSame(2, $response->json('data.metrics.sale_count'));
    }

    /**
     * A soft-deleted customer named as a `customer_id` filter is still not
     * ranked.
     *
     * The filter itself is accepted: `ReportFilterRequest` validates the id with
     * `Rule::exists('customers', 'id')`, which queries the table directly and so
     * does not apply the model's soft-delete global scope. What matters for this
     * stage is the outcome — the ranking's own `whereNull('customers.deleted_at')`
     * still drops them, so the filter cannot be used to pull a deleted customer's
     * name back onto the report.
     *
     * Pinned as the behaviour that actually exists, rather than as one this stage
     * invented: the filter's validation is left exactly as it was, because
     * tightening it would change what every *other* report accepts too, which is
     * outside this stage's scope.
     *
     * @test
     */
    public function a_soft_deleted_customer_named_as_a_filter_is_still_not_ranked(): void
    {
        $removed = $this->customer('Removed Customer');
        $this->customerSale($removed, self::DAY, '4000.00');

        $removed->delete();
        $this->assertSoftDeleted($removed);

        $response = $this->report(self::DAY, self::DAY, ['customer_id' => $removed->id])
            ->assertStatus(200);

        $this->assertSame([], $this->topCustomers($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Removed Customer',
            json_encode($this->topCustomers($response), JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's sale never appears, however it is reached.
     *
     * @test
     */
    public function another_businesss_sale_is_excluded(): void
    {
        $mine = $this->customer('Mine');

        $foreign = Business::factory()->create();
        $theirs = $this->customer('Theirs', $foreign);

        $this->customerSale($mine, self::DAY, '100.00');
        $this->customerSale($theirs, self::DAY, '9000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A foreign customer joined to a **local** sale cannot leak.
     *
     * This is the Stage 3 finding, one hop sideways, and the most important test
     * in this file. `sales.customer_id` is a plain foreign key with no composite
     * constraint tying it to the invoice's own business, so a corrupted or
     * hand-edited row can point this tenant's invoice at another tenant's
     * customer. Scoping `sales.business_id` alone would still group that invoice
     * and print the other business's customer's **name** on this report — a leak
     * of an identity rather than of a total, which is the worse of the two.
     *
     * @test
     */
    public function a_foreign_customer_on_a_local_sale_cannot_leak(): void
    {
        $mine = $this->customer('Mine');

        $foreign = Business::factory()->create();
        $theirs = $this->customer('Foreign Secret Name', $foreign);

        $this->customerSale($mine, self::DAY, '100.00');

        // This tenant's invoice, pointing at the other tenant's customer.
        $crossTenant = $this->sale(self::DAY, '9000.00', ['customer_id' => $theirs->id]);
        $this->assertSame($this->business->id, $crossTenant->business_id);
        $this->assertSame($theirs->id, $crossTenant->fresh()->customer_id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Foreign Secret Name',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        // Only the ranking excludes it: the invoice total is this tenant's own
        // revenue and is untouched.
        $this->assertTrue(Money::equals('9100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The same customer *name* in two businesses never leaks or collapses.
     *
     * Names are not unique, so grouping by name alone would merge two tenants'
     * customers into one figure. The grouping key is the customer id, and the
     * tenant guard decides which of them is ever seen.
     *
     * @test
     */
    public function the_same_customer_name_in_two_businesses_does_not_leak_or_collapse(): void
    {
        $mine = $this->customer('Ayesha Rahman');

        $foreign = Business::factory()->create();
        $theirs = $this->customer('Ayesha Rahman', $foreign);

        $this->customerSale($mine, self::DAY, '100.00');
        $this->customerSale($theirs, self::DAY, '9000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(1, $this->topCustomers($response));
        $this->assertSame([$mine->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $this->topCustomers($response)[0]['total_sales']));
    }

    /**
     * Two local customers sharing a name are still two rows.
     *
     * The local twin of the cross-tenant case: if the grouping were on the name,
     * these two invoices would merge into one row and one customer's revenue would
     * be attributed to another.
     *
     * @test
     */
    public function two_local_customers_sharing_a_name_stay_separate(): void
    {
        $first = $this->customer('Duplicate Name');
        $second = $this->customer('Duplicate Name');

        $this->customerSale($first, self::DAY, '300.00');
        $this->customerSale($second, self::DAY, '700.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->byCustomer($response);

        $this->assertCount(2, $this->topCustomers($response));
        $this->assertSame([$second->id, $first->id], $this->order($response));
        $this->assertTrue(Money::equals('300.00', $rows[$first->id]['total_sales']));
        $this->assertTrue(Money::equals('700.00', $rows[$second->id]['total_sales']));
    }

    /**
     * A foreign payment cannot pull a foreign sale or customer into the ranking.
     *
     * The payment filter resolves sales through `payments`, so it is the easiest
     * way for a foreign invoice to be dragged in. It must not be: the scoped
     * invoice set is the only door into the ranking.
     *
     * @test
     */
    public function a_foreign_payment_cannot_pull_a_foreign_sale_or_customer_in(): void
    {
        $mine = $this->customer('Mine');

        $mineSale = $this->customerSale($mine, self::DAY, '100.00');
        $this->pay($mineSale, Payment::PAYMENT_METHOD_CARD, '100.00');

        $foreign = Business::factory()->create();
        $theirs = $this->customer('Theirs', $foreign);
        $theirSale = $this->customerSale($theirs, self::DAY, '9000.00');
        $this->pay($theirSale, Payment::PAYMENT_METHOD_CARD, '9000.00');

        $response = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CARD])
            ->assertStatus(200);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A `customer_id` filter belonging to another business is rejected outright.
     *
     * So the filter cannot be used as a tenant probe to discover that a customer
     * id exists elsewhere.
     *
     * @test
     */
    public function a_customer_filter_across_businesses_is_rejected(): void
    {
        $foreign = Business::factory()->create();
        $theirs = $this->customer('Theirs', $foreign);

        $this->report(self::DAY, self::DAY, ['customer_id' => $theirs->id])->assertStatus(422);
    }

    /**
     * The authenticated caller sees their own business's customer correctly.
     *
     * The positive counterpart to the leak tests above, so the guards cannot be
     * "passed" by returning nothing at all.
     *
     * @test
     */
    public function an_authenticated_users_own_customer_is_reported_correctly(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $this->customerSale($customer, self::DAY, '4250.00');
        $this->customerSale($customer, '2026-01-16', '750.00');

        $response = $this->report('2026-01-01', '2026-01-31')->assertStatus(200);
        $rows = $this->topCustomers($response);

        $this->assertCount(1, $rows);
        $this->assertSame($customer->id, $rows[0]['customer_id']);
        $this->assertSame('Ayesha Rahman', $rows[0]['customer_name']);
        $this->assertSame(2, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('5000.00', $rows[0]['total_sales']));
    }
    // ------------------------------------------------------------------
    // Access and permissions
    // ------------------------------------------------------------------

    /**
     * No new permission: the ranking sits behind the existing `reports.view`
     * gate, on the existing route, with nothing new to grant.
     *
     * @test
     */
    public function the_ranking_is_not_reachable_without_reports_view(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '1000.00');

        $url = '/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY;

        $this->flushHeaders();
        $this->getJson($url)->assertStatus(401);

        $this->actingAsUnprivilegedUser($this->business);
        $this->getJson($url)->assertStatus(403)->assertJson(['success' => false]);
    }

    /**
     * Holding `reports.view` is enough, and `reports.view` *alone* is enough.
     *
     * @test
     */
    public function reports_view_alone_authorises_the_ranking(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '1000.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topCustomers($response));
    }

    /**
     * A reports viewer needs no customer-management permission at all.
     *
     * The stage must not smuggle in a `customers.view` requirement: reading a
     * customer *name* inside a sales report is a reporting fact, not access to
     * the customer module.
     *
     * @test
     */
    public function customers_view_is_not_required_when_reports_view_exists(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '1000.00');

        $reportsViewer = $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame('Ayesha Rahman', $this->topCustomers($response)[0]['customer_name']);
        $this->assertFalse(
            $reportsViewer->fresh()->hasPermission(PermissionService::CUSTOMERS_VIEW),
            'the fixture must hold only reports.view for this test to mean anything',
        );
    }

    /**
     * `customers.view` on its own does not open the report.
     *
     * @test
     */
    public function customers_view_alone_does_not_authorise_the_report(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '1000.00');

        $this->userWithPermissions([PermissionService::CUSTOMERS_VIEW], $this->business->id);

        $this->report(self::DAY, self::DAY)->assertStatus(403);
    }

    /**
     * No customer-reporting permission exists to be granted or revoked.
     *
     * The stage introduces a field, not a capability, so the catalogue must be
     * exactly what it was.
     *
     * @test
     */
    public function no_customer_reporting_permission_is_introduced(): void
    {
        $slugs = PermissionService::catalogueSlugs();

        $this->assertContains(PermissionService::REPORTS_VIEW, $slugs);

        $forbidden = [
            'reports.customers',
            'reports.top_customers',
            'reports.sales_customers',
            'reports.sales_by_customer',
            'reports.customers.view',
        ];

        foreach ($forbidden as $slug) {
            $this->assertNotContains($slug, $slugs, "{$slug} must not exist");
        }
    }

    /**
     * The stage adds a field to an existing report and needs no new schema.
     *
     * Asserted rather than assumed: a report that quietly required a table would
     * mean the migration policy had been broken without anyone noticing.
     *
     * @test
     */
    public function no_migration_is_required_for_the_ranking(): void
    {
        // The two tables the aggregate joins are ones the schema already had.
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasTable('sales'));

        // The relationship is the existing one, and the customer is soft-deletable
        // — which is what the ranking's `whereNull` states explicitly.
        $this->assertTrue(Schema::hasColumn('sales', 'customer_id'));
        $this->assertTrue(Schema::hasColumn('customers', 'deleted_at'));

        // The canonical sales total column already exists, so no snapshot column
        // was needed to answer the question.
        $this->assertTrue(Schema::hasColumn('sales', 'grand_total'));

        // Nothing report-shaped was introduced.
        foreach (['report_summaries', 'report_customer_summaries', 'report_snapshots'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist");
        }
    }
    // ------------------------------------------------------------------
    // Query shape
    // ------------------------------------------------------------------

    /**
     * The ranking is exactly one grouped aggregate in the database.
     *
     * Proven by inspecting the SQL rather than the row count: an implementation
     * that fetched the invoices and grouped them in PHP would return the same
     * numbers with the same query count, but would scale with the *size* of the
     * period instead of its length.
     *
     * @test
     */
    public function the_ranking_is_one_grouped_aggregate_in_the_database(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, '2026-01-10', '100.00');
        $this->customerSale($customer, '2026-02-10', '200.00');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

            // `customers.name` is only the customer aggregate's to select, so this
            // matches the ranking and not the daily or monthly series beside it.
            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'customers.name'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $grouped, 'exactly one grouped customer aggregate');

        // Identifier quoting is driver-specific — SQLite wraps in `"`, MySQL in
        // backticks — so the quoting is stripped before the shape is asserted.
        // What is being checked is the *clauses*, not how either driver spells
        // them.
        $sql = strtolower(str_replace(['"', '`'], '', $grouped[0]['query']));

        // The join, the aggregate and the top-N cut are all server-side.
        $this->assertStringContainsString('inner join customers on customers.id = sales.customer_id', $sql);
        $this->assertStringContainsString('sum(sales.grand_total)', $sql);
        $this->assertStringContainsString('count(sales.id)', $sql);
        $this->assertStringContainsString('limit 10', $sql);

        // Grouped by both selected customer columns, for `ONLY_FULL_GROUP_BY`.
        $this->assertStringContainsString('group by customers.id, customers.name', $sql);

        // Ranked by the aggregate, with the deterministic tie-break.
        $this->assertStringContainsString('order by total_sales desc, customers.id asc', $sql);

        // The tenant guard is on the *join*, not merely inherited from the parent
        // scope. This is the assertion that would fail if the second guard were
        // ever dropped.
        $this->assertStringContainsString('customers.business_id = ?', $sql);
        $this->assertStringContainsString('customers.deleted_at is null', $sql);

        // And the parent scope is still there too: both boundaries, not one.
        $this->assertStringContainsString('sales.business_id = ?', $sql);

        // No PHP-side grouping helper leaked into the SQL, and no second round
        // trip is hiding in here.
        $this->assertStringNotContainsString('sale_items', $sql);
        $this->assertStringNotContainsString('payments', $sql);
    }

    /**
     * The query count is fixed regardless of the window length.
     *
     * A per-customer implementation would issue one query per customer, so the
     * cost would grow with the catalogue and the range rather than with the data.
     * Comparing a one-month window against a year-long one makes that visible:
     * the counts must be identical.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_window_length(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        for ($month = 1; $month <= 12; $month++) {
            $this->customerSale($customer, sprintf('2026-%02d-10', $month), '100.00');
        }

        // A warm-up request first: the very first call in a test also resolves
        // the Sanctum token and the account status, which would otherwise be
        // counted as part of the short window and make the comparison useless.
        $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $oneMonth = $this->countQueries(fn () => $this->report('2026-01-01', '2026-01-31')->assertStatus(200));
        $twelveMonths = $this->countQueries(fn () => $this->report('2026-01-01', '2026-12-31')->assertStatus(200));

        $this->assertSame($oneMonth, $twelveMonths, 'query count must not grow with the number of months');
        // A ceiling rather than an exact figure: it catches a per-month or
        // per-customer implementation without breaking when another aggregate is
        // added to the report.
        $this->assertLessThanOrEqual(16, $twelveMonths);
    }

    /**
     * The query count is fixed regardless of the number of customers.
     *
     * The N+1 guard proper: the window is held fixed while the number of distinct
     * customers inside it varies.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_customers(): void
    {
        $first = $this->customer('First');
        $this->customerSale($first, self::DAY, '100.00');

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $oneCustomer = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        for ($index = 1; $index <= 24; $index++) {
            $this->customerSale($this->customer('Customer '.$index), self::DAY, '100.00');
        }

        $twentyFive = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(
            $oneCustomer,
            $twentyFive,
            'query count must not grow with the number of customers',
        );
    }

    /**
     * The query count is fixed regardless of the number of sales.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_sales(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $this->customerSale($customer, self::DAY, '100.00');

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $fewSales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        for ($index = 1; $index <= 50; $index++) {
            $this->customerSale($customer, self::DAY, '100.00');
        }

        $manySales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame($fewSales, $manySales, 'query count must not grow with the number of sales');
    }

    /**
     * The database cuts the ranking before it reaches PHP.
     *
     * The aggregate is bounded by the top N, so the report cannot be answering
     * from a PHP-side sort of the full set: a period with far more customers than
     * the limit still returns exactly the limit.
     *
     * @test
     */
    public function the_database_cuts_the_ranking_before_it_reaches_php(): void
    {
        for ($index = 1; $index <= 40; $index++) {
            $this->customerSale($this->customer('Customer '.$index), self::DAY, $index.'.00');
        }

        $rows = $this->topCustomers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(self::LIMIT, $rows);
        // 40.00 down to 31.00 — the ten highest, in descending order.
        $this->assertSame(
            ['40.00', '39.00', '38.00', '37.00', '36.00', '35.00', '34.00', '33.00', '32.00', '31.00'],
            array_column($rows, 'total_sales'),
        );
    }
    // ------------------------------------------------------------------
    // Compatibility with the existing contract
    // ------------------------------------------------------------------

    /**
     * Adding the ranking changed no existing field on the report.
     *
     * This is the regression guard for the whole stage: every pre-existing metric
     * is asserted here on a report that *does* return customers, so a future edit
     * that let the new aggregate disturb an old one fails loudly.
     *
     * @test
     */
    public function every_existing_metric_is_unchanged_by_the_ranking(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $product = $this->product('Latte');

        // One customer's invoice with a return and a refund payment, plus a
        // walk-in invoice, so the figures below have something non-trivial to be
        // wrong about.
        $sale = $this->customerSale($customer, self::DAY, '2000.00');
        $line = $this->line($sale, $product, '20', '2000.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '2000.00');

        // In the totals, absent from the ranking.
        $walkIn = $this->sale(self::DAY, '500.00');
        $this->line($walkIn, $this->product('Rice'), '5', '500.00');

        $this->completedReturn($line, self::DAY, '400.00');

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $this->assertSame(2, $m['sale_count']);
        $this->assertTrue(Money::equals('2500.00', $m['total_sales']));
        $this->assertTrue(Money::equals('2100.00', $m['net_sales']));
        $this->assertSame(1, $m['returns_count']);
        $this->assertTrue(Money::equals('400.00', $m['returns_amount']));
        $this->assertTrue(Money::equals('2500.00', $m['paid_amount']));
        $this->assertTrue(Money::equals('0.00', $m['due_amount']));
        $this->assertSame(1, $m['refund_count']);
        $this->assertTrue(Money::equals('400.00', $m['refund_amount']));
        $this->assertTrue(Money::equals('2100.00', $m['net_collected_amount']));

        // And the new field, on the same report.
        $this->assertCount(1, $m['top_customers']);
        $this->assertTrue(Money::equals('2000.00', $m['top_customers'][0]['total_sales']));
    }

    /**
     * `top_products` and `top_categories` are untouched by the customer join.
     *
     * The customer aggregate joins `customers` onto the same scoped builder the
     * product and category aggregates read. This is the regression that would
     * catch that join changing their population — or their figures.
     *
     * @test
     */
    public function top_products_and_top_categories_are_unchanged_by_the_customer_ranking(): void
    {
        $category = $this->category('Beverages');
        $customer = $this->customer('Ayesha Rahman');

        $sale = $this->customerSale($customer, self::DAY, '2000.00');
        $this->line($sale, $this->product('Latte', $category), '10', '1000.00');
        // An uncategorised product: reaches the product ranking, not the category one.
        $this->line($sale, $this->product('Rice'), '10', '1000.00');

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $this->assertCount(2, $m['top_products']);
        $this->assertCount(1, $m['top_categories']);
        $this->assertSame($category->id, $m['top_categories'][0]['category_id']);
        $this->assertTrue(Money::equals('1000.00', $m['top_categories'][0]['total_sales']));
    }

    /**
     * The `period` block and the rest of the envelope are unchanged.
     *
     * @test
     */
    public function the_period_block_is_unchanged(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame('Sales report generated successfully', $response->json('message'));
        $this->assertSame(self::DAY, $response->json('data.period.from'));
        $this->assertSame(self::DAY, $response->json('data.period.to'));
    }

    /**
     * `daily_series` and `monthly_series` are untouched.
     *
     * @test
     */
    public function the_daily_and_monthly_series_are_unchanged(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->customerSale($customer, '2026-01-15', '100.00');
        $this->customerSale($customer, '2026-02-20', '200.00');

        $m = $this->report('2026-01-01', '2026-02-28')->assertStatus(200)->json('data.metrics');

        $this->assertSame('100.00', $m['daily_series'][14]['total_sales']);
        $this->assertSame('2026-01-15', $m['daily_series'][14]['date']);
        $this->assertSame('0.00', $m['daily_series'][15]['total_sales'], 'the 16th had no sales');
        $this->assertSame('200.00', $m['daily_series'][50]['total_sales']);

        $this->assertSame('2026-01', $m['monthly_series'][0]['month']);
        $this->assertTrue(Money::equals('100.00', $m['monthly_series'][0]['total_sales']));
        $this->assertSame('2026-02', $m['monthly_series'][1]['month']);
        $this->assertTrue(Money::equals('200.00', $m['monthly_series'][1]['total_sales']));
    }

    /**
     * The report's other endpoints are untouched by a change to `sales()`.
     *
     * @test
     */
    public function the_other_report_endpoints_are_unaffected(): void
    {
        $this->customerSale($this->customer('Ayesha Rahman'), self::DAY, '100.00');

        $this->getJson('/api/v1/reports/purchases?date_from='.self::DAY.'&date_to='.self::DAY)->assertStatus(200);
        $this->getJson('/api/v1/reports/expenses?date_from='.self::DAY.'&date_to='.self::DAY)->assertStatus(200);
        $this->getJson('/api/v1/reports/profit-loss?date_from='.self::DAY.'&date_to='.self::DAY)->assertStatus(200);
        $this->getJson('/api/v1/reports/inventory')->assertStatus(200);
        $this->getJson('/api/v1/reports/dashboard')->assertStatus(200);
    }
}
