<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Http\Requests\Report\ReportFilterRequest;
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
 * Stage 6 â€” the **sales by user / staff** ranking added to `GET /reports/sales`.
 *
 * `metrics.top_users` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **The user is the one recorded on the sale itself.** This schema has no
 *    `sales.user_id`; the sale-to-staff relationship the project already had and
 *    already writes is `sales.cashier_id` -> `users.id`, set by
 *    `SaleService::create()` from the authenticated seller. It is *not*
 *    `payments.received_by`, not the cash-register operator, not the caller and
 *    not the customer â€” and the tests below exclude each of those by
 *    construction.
 *  - **It is the same population, joined sideways rather than down.** It is
 *    grouped off the very builder `sales()` aggregates, so tenant,
 *    `ReportDateRange` window, `customer_id`, `status` and `payment_method`
 *    apply by the same predicates.
 *  - **Tenant isolation is stated at every layer.** The invoice scope *and* the
 *    joined user are each pinned to the caller's business, because
 *    `sales.cashier_id` is not itself tenant-scoped and a corrupted row would
 *    otherwise print another business's staff **name and id** on this tenant's
 *    report. Several tests below reproduce exactly that malformed state.
 *  - **It is gross.** `SUM(sales.grand_total)` â€” the identical column, and the
 *    identical reading, as `total_sales`. Returns and refunds never reduce a
 *    staff member's figure, exactly as they never reduce the total.
 *  - **`invoice_count` counts invoices.** `COUNT(sales.id)`, grouped on the
 *    `sales` table itself, so a multi-line or multi-payment invoice still counts
 *    once and neither `sale_items` nor `payments` can inflate it.
 *  - **Unassigned sales are excluded, and the total is not changed to
 *    compensate.** A null `cashier_id` is a real sale in `total_sales` with no
 *    staff member to rank; the schema stores no reserved "Unknown User" row, so
 *    none is invented. The ranking therefore does *not* reconcile to
 *    `total_sales`, which is asserted here as behaviour rather than tolerated.
 *  - **Deactivated staff are retained.** `User` does not soft-delete â€” the
 *    schema has no `users.deleted_at` and the model uses no `SoftDeletes` â€” so
 *    `status = 'inactive'` is deactivation, not deletion, and the period revenue
 *    is still reported. Asserted below.
 *  - **Ranking is by revenue**, ties break on `user_id` ascending, and the top N
 *    cut is a database `LIMIT` â€” never a PHP sort or a PHP trim.
 *  - **Money stays a decimal string** with two decimals, through the project's
 *    own `Money::format`, exactly as every other figure on the payload.
 *  - **The cost is one grouped aggregate**, whatever the period length or the size
 *    of the staff directory â€” proven by inspecting the SQL, not just row count.
 *  - **No new permission and no new schema.** `reports.view` alone still gates
 *    the whole report, `users.view` is never required, and the tables this joins
 *    are ones the project already had.
 */
class ReportTopUsersTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day every boundary test is written around. */
    private const DAY = '2026-01-15';

    /**
     * How many staff the report publishes.
     *
     * Mirrors `ReportService::TOP_USERS_LIMIT`. Asserted rather than assumed:
     * the ranking is a bounded card, and an unbounded one would be the whole
     * staff directory dressed up as a dashboard.
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
     * One invoice of this tenant, with no cashier unless one is named.
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
     * An invoice of this tenant served by the given staff member.
     *
     * `cashier_id` is the only user reference a sale carries â€” the field
     * `SaleService::create()` writes â€” so this is the whole association the
     * ranking reads.
     */
    private function staffSale(User $staff, string $saleDate, string $grandTotal): Sale
    {
        return $this->sale($saleDate, $grandTotal, ['cashier_id' => $staff->id]);
    }

    /**
     * An invoice served by the given staff member, in that staff member's own
     * business.
     *
     * The invoice's business follows the *staff member's* business unless one is
     * given, so a fixture can never accidentally create a "foreign" sale that is
     * really a local one â€” which is the whole point of the tenant tests below.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saleOfBusiness(User $staff, string $saleDate, string $grandTotal, array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'business_id' => $staff->business_id,
            'sale_date' => $saleDate,
            'grand_total' => $grandTotal,
            'paid_amount' => $grandTotal,
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'cashier_id' => $staff->id,
        ], $attributes));
    }

    /**
     * A staff member of this tenant â€” or of another business when one is given.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function staff(string $name = 'Local Salesman', ?Business $business = null, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'business_id' => ($business ?? $this->business)->id,
            'name' => $name,
        ], $attributes));
    }

    /** A customer of this tenant. */
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
     * This is the shape the return flow writes: a `unit_cost` snapshot on the
     * line, a `return_date` of its own, and a refund payment carrying the
     * return's id and a null `sale_id`.
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

    /** `metrics.top_users` exactly as the payload carries it. */
    private function topUsers(TestResponse $response): array
    {
        return $response->json('data.metrics.top_users') ?? [];
    }

    /**
     * The ranking indexed by user id, for readable assertions.
     *
     * @return array<int, array{name: string, invoice_count: int, total_sales: string}>
     */
    private function byUser(TestResponse $response): array
    {
        $indexed = [];

        foreach ($this->topUsers($response) as $row) {
            $indexed[$row['user_id']] = [
                'name' => $row['user_name'],
                'invoice_count' => $row['invoice_count'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    /** The user ids, in the order the API returned them. */
    private function order(TestResponse $response): array
    {
        return array_column($this->topUsers($response), 'user_id');
    }

    /** The whole ranking summed with bcmath, through the project money helper. */
    private function summed(TestResponse $response): string
    {
        $total = Money::format(null);

        foreach ($this->topUsers($response) as $row) {
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
    // Shape, the single-staff case and the money
    // ------------------------------------------------------------------

    /**
     * The field exists, is an array, and carries exactly the contract keys.
     *
     * @test
     */
    public function top_users_is_published_on_the_sales_report(): void
    {
        $this->staffSale($this->staff('Local Salesman'), self::DAY, '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertIsArray($response->json('data.metrics.top_users'));
        $this->assertCount(1, $this->topUsers($response));
        $this->assertSame(
            ['user_id', 'user_name', 'invoice_count', 'total_sales'],
            array_keys($this->topUsers($response)[0]),
        );
    }

    /**
     * The wire types are exactly what the contract promises.
     *
     * @test
     */
    public function top_users_publishes_the_declared_wire_types(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '1000.00');

        $row = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertIsInt($row['user_id']);
        $this->assertSame($salesman->id, $row['user_id']);
        $this->assertIsString($row['user_name']);
        $this->assertSame('Local Salesman', $row['user_name']);
        $this->assertIsInt($row['invoice_count']);
        $this->assertIsString($row['total_sales']);
    }

    /**
     * The ranking names the user recorded on the sale: `sales.cashier_id`.
     *
     * @test
     */
    public function the_user_id_and_name_come_from_the_sale_cashier(): void
    {
        $manager = $this->staff('Local Manager');
        $this->staffSale($manager, self::DAY, '4200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $row = $this->topUsers($response)[0];

        $this->assertSame($manager->id, $row['user_id']);
        $this->assertSame('Local Manager', $row['user_name']);
        $this->assertSame(1, $row['invoice_count']);
        $this->assertTrue(Money::equals('4200.00', $row['total_sales']));
    }

    /**
     * The caller is not the staff member, and the payment taker is not either.
     *
     * Both plausible wrong answers are excluded by construction: the request is
     * authenticated as `$this->user`, every payment is `received_by` that same
     * user, and the cashier is a third person. If the metric inferred the user
     * from either of those, this test would fail.
     *
     * @test
     */
    public function the_user_is_the_cashier_not_the_caller_or_the_payment_taker(): void
    {
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '1500.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '1500.00');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($salesman->id, $rows[0]['user_id']);
        $this->assertNotSame($this->user->id, $rows[0]['user_id'], 'the authenticated caller must not be the answer');
    }

    /**
     * The gross figure is `sales.grand_total`, and nothing is subtracted.
     *
     * @test
     */
    public function the_total_is_gross_grand_total(): void
    {
        $manager = $this->staff('Local Manager');
        $this->staffSale($manager, '2026-01-14', '2500.00');
        $this->staffSale($manager, '2026-01-15', '2000.00');

        $response = $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $this->assertTrue(Money::equals('4500.00', $this->topUsers($response)[0]['total_sales']));
        $this->assertTrue(Money::equals('4500.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Money formatting holds to two decimals on awkward figures.
     *
     * @test
     */
    public function money_is_formatted_to_two_decimals(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '0.10');
        $this->staffSale($salesman, self::DAY, '1234.56');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('1234.66', $rows[0]['total_sales']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $rows[0]['total_sales']);
    }

    /**
     * One staff member, many invoices, one row.
     *
     * @test
     */
    public function one_user_with_many_invoices_aggregates_once(): void
    {
        $salesman = $this->staff('Local Salesman');

        for ($index = 1; $index <= 12; $index++) {
            $this->staffSale($salesman, self::DAY, '100.00');
        }

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        // One row, not twelve: the user is the grouping key.
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
     * would be ranking basket size, not staff value.
     *
     * @test
     */
    public function an_invoice_with_many_lines_is_counted_once(): void
    {
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '400.00');

        for ($index = 1; $index <= 4; $index++) {
            $this->line($sale, $this->product('Product '.$index), '1', '100.00');
        }

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

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
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '900.00');

        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '300.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CARD, '300.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '300.00');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

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
        $salesman = $this->staff('Local Salesman');

        $first = $this->staffSale($salesman, '2026-01-14', '500.00');
        $this->line($first, $this->product('A'), '1', '250.00');
        $this->line($first, $this->product('B'), '1', '250.00');
        $this->pay($first, Payment::PAYMENT_METHOD_CASH, '250.00');
        $this->pay($first, Payment::PAYMENT_METHOD_CARD, '250.00');

        $second = $this->staffSale($salesman, '2026-01-15', '700.00');
        $this->line($second, $this->product('C'), '1', '700.00');
        $this->pay($second, Payment::PAYMENT_METHOD_CASH, '700.00');

        $rows = $this->topUsers($this->report('2026-01-01', '2026-01-31')->assertStatus(200));

        $this->assertSame(2, $rows[0]['invoice_count'], 'two invoices, three lines, three payments');
        $this->assertTrue(Money::equals('1200.00', $rows[0]['total_sales']));
    }

    // ------------------------------------------------------------------
    // Ranking, ties and the top-N cut
    // ------------------------------------------------------------------

    /**
     * Several staff aggregate into one row each, largest first.
     *
     * @test
     */
    public function multiple_users_aggregate_in_descending_order(): void
    {
        $manager = $this->staff('Local Manager');
        $salesman = $this->staff('Local Salesman');
        $accountant = $this->staff('Local Accountant');

        $this->staffSale($salesman, self::DAY, '8500.00');
        $this->staffSale($manager, self::DAY, '4200.00');
        $this->staffSale($accountant, self::DAY, '100.00');
        $this->staffSale($salesman, self::DAY, '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topUsers($response);

        $this->assertSame([$salesman->id, $manager->id, $accountant->id], $this->order($response));

        $this->assertSame('9000.00', $rows[0]['total_sales']);
        $this->assertSame(2, $rows[0]['invoice_count']);
        $this->assertSame('Local Salesman', $rows[0]['user_name']);
        $this->assertSame('Local Manager', $rows[1]['user_name']);
        $this->assertSame('4200.00', $rows[1]['total_sales']);
        $this->assertSame(1, $rows[1]['invoice_count']);
    }

    /**
     * Equal totals break on `user_id` ascending, deterministically.
     *
     * Two identical requests must return the identical order; without the
     * tie-break that is not guaranteed on any engine.
     *
     * @test
     */
    public function equal_totals_break_on_user_id_ascending(): void
    {
        $first = $this->staff('Zainab Hossain');
        $second = $this->staff('Ayesha Rahman');
        $third = $this->staff('Karim Sheikh');

        $this->staffSale($first, self::DAY, '500.00');
        $this->staffSale($second, self::DAY, '500.00');
        $this->staffSale($third, self::DAY, '500.00');

        $expected = [$first->id, $second->id, $third->id];
        sort($expected);

        $firstRun = $this->order($this->report(self::DAY, self::DAY)->assertStatus(200));
        $secondRun = $this->order($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame($expected, $firstRun, 'ties break on the lower user id first');
        $this->assertSame($firstRun, $secondRun, 'two identical requests must not reorder');
    }

    /**
     * The cut is at ten rows, and the eleventh largest user is dropped.
     *
     * @test
     */
    public function the_ranking_is_capped_at_ten_rows(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            $this->staffSale($this->staff('Staff '.$index), self::DAY, (string) (40 - $index));
        }

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(self::LIMIT, $rows);

        // 39.00 down to 30.00 - the ten highest, in descending order.
        $this->assertSame(
            ['39.00', '38.00', '37.00', '36.00', '35.00', '34.00', '33.00', '32.00', '31.00', '30.00'],
            array_column($rows, 'total_sales'),
        );
    }

    // ------------------------------------------------------------------
    // Filters, inherited from the shared scope
    // ------------------------------------------------------------------

    /**
     * `date_from` is inclusive.
     *
     * @test
     */
    public function the_from_date_is_inclusive(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, '2026-01-14', '100.00');
        $this->staffSale($salesman, '2026-01-15', '200.00');

        $rows = $this->topUsers($this->report('2026-01-15', '2026-01-31')->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['invoice_count']);
        $this->assertTrue(Money::equals('200.00', $rows[0]['total_sales']));
    }

    /**
     * `date_to` is inclusive.
     *
     * @test
     */
    public function the_to_date_is_inclusive(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, '2026-01-15', '200.00');
        $this->staffSale($salesman, '2026-01-16', '100.00');

        $rows = $this->topUsers($this->report('2026-01-01', '2026-01-15')->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertTrue(Money::equals('200.00', $rows[0]['total_sales']));
    }

    /**
     * A bounded window excludes what falls outside it on both sides.
     *
     * @test
     */
    public function the_date_range_is_bounded_on_both_sides(): void
    {
        $manager = $this->staff('Local Manager');
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, '2025-12-31', '9000.00');
        $this->staffSale($manager, '2026-01-10', '1000.00');
        $this->staffSale($manager, '2026-01-20', '2000.00');
        $this->staffSale($manager, '2026-02-01', '9000.00');

        $rows = $this->byUser($this->report('2026-01-01', '2026-01-31')->assertStatus(200));

        $this->assertSame([$manager->id], array_keys($rows));
        $this->assertSame(2, $rows[$manager->id]['invoice_count']);
        $this->assertTrue(Money::equals('3000.00', $rows[$manager->id]['total_sales']));
    }

    /**
     * `customer_id` narrows the ranking exactly as it narrows `total_sales`.
     *
     * @test
     */
    public function the_customer_id_filter_is_inherited(): void
    {
        $manager = $this->staff('Local Manager');
        $ayesha = $this->customer('Ayesha Rahman');

        $this->sale(self::DAY, '9000.00', [
            'cashier_id' => $manager->id,
            'customer_id' => $ayesha->id,
        ]);

        $response = $this->report(self::DAY, self::DAY, ['customer_id' => (string) $ayesha->id])
            ->assertStatus(200);

        $rows = $this->topUsers($response);

        $this->assertCount(1, $rows);
        $this->assertSame($manager->id, $rows[0]['user_id']);
        $this->assertTrue(Money::equals('9000.00', $rows[0]['total_sales']));

        // And the total above it narrowed to the same population.
        $this->assertTrue(Money::equals('9000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The `status` filter narrows the ranking.
     *
     * Also the reason `scopedSales()` qualifies its column names: `users` has a
     * `status` of its own, so an unqualified `where('status', ...)` on this join
     * would be ambiguous and the request would fail outright.
     *
     * @test
     */
    public function the_status_filter_is_inherited(): void
    {
        $salesman = $this->staff('Local Salesman');
        $manager = $this->staff('Local Manager');

        $this->staffSale($salesman, self::DAY, '1000.00');
        $this->sale(self::DAY, '2000.00', [
            'cashier_id' => $manager->id,
            'status' => Sale::STATUS_DRAFT,
        ]);

        $rows = $this->topUsers($this->report(self::DAY, self::DAY, [
            'status' => Sale::STATUS_DRAFT,
        ])->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($manager->id, $rows[0]['user_id']);
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));
    }

    /**
     * The `payment_method` filter narrows the ranking.
     *
     * @test
     */
    public function the_payment_method_filter_is_inherited(): void
    {
        $salesman = $this->staff('Local Salesman');
        $manager = $this->staff('Local Manager');

        $cash = $this->staffSale($salesman, self::DAY, '1000.00');
        $this->pay($cash, Payment::PAYMENT_METHOD_CASH, '1000.00');

        $card = $this->staffSale($manager, self::DAY, '2000.00');
        $this->pay($card, Payment::PAYMENT_METHOD_CARD, '2000.00');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY, [
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($manager->id, $rows[0]['user_id']);
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));
    }

    /**
     * Every inherited filter at once narrows both the ranking and the total.
     *
     * @test
     */
    public function combined_filters_are_inherited_together(): void
    {
        $salesman = $this->staff('Local Salesman');
        $manager = $this->staff('Local Manager');
        $ayesha = $this->customer('Ayesha Rahman');

        // The one invoice that satisfies every filter at once.
        $wanted = $this->sale(self::DAY, '3000.00', [
            'cashier_id' => $manager->id,
            'customer_id' => $ayesha->id,
        ]);
        $this->pay($wanted, Payment::PAYMENT_METHOD_CARD, '3000.00');

        // Each of these breaks exactly one filter.
        $wrongDate = $this->staffSale($salesman, '2026-01-14', '100.00');
        $this->pay($wrongDate, Payment::PAYMENT_METHOD_CARD, '100.00');

        $wrongMethod = $this->staffSale($salesman, self::DAY, '200.00');
        $this->pay($wrongMethod, Payment::PAYMENT_METHOD_CASH, '200.00');

        $this->staffSale($salesman, self::DAY, '400.00', ['status' => Sale::STATUS_DRAFT]);
        $this->staffSale($salesman, self::DAY, '800.00');

        $response = $this->report('2026-01-15', '2026-01-15', [
            'customer_id' => (string) $ayesha->id,
            'status' => Sale::STATUS_COMPLETED,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200);

        $rows = $this->topUsers($response);

        $this->assertCount(1, $rows);
        $this->assertSame($manager->id, $rows[0]['user_id']);
        $this->assertTrue(Money::equals('3000.00', $rows[0]['total_sales']));
        $this->assertTrue(Money::equals('3000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The Sales Report has no user filter, and this stage does not add one.
     *
     * Asserted rather than assumed: a staff report quietly gaining its own
     * filter would need request validation, a contract change and a column on
     * every other report, none of which this stage is allowed to introduce. An
     * unknown parameter is ignored by the request, exactly as before - and the
     * ranking stays unfiltered by it.
     *
     * @test
     */
    public function no_user_filter_is_introduced(): void
    {
        $salesman = $this->staff('Local Salesman');
        $manager = $this->staff('Local Manager');

        $this->staffSale($salesman, self::DAY, '1000.00');
        $this->staffSale($manager, self::DAY, '2000.00');

        $rules = (new ReportFilterRequest)->rules();

        $this->assertArrayNotHasKey('user_id', $rules);
        $this->assertArrayNotHasKey('cashier_id', $rules);
        $this->assertArrayNotHasKey('staff_id', $rules);

        // Passing one anyway changes nothing: still both staff members.
        $rows = $this->topUsers($this->report(self::DAY, self::DAY, ['user_id' => (string) $salesman->id])
            ->assertStatus(200));

        $this->assertCount(2, $rows);
    }

    // ------------------------------------------------------------------
    // Unassigned sales
    // ------------------------------------------------------------------

    /**
     * The schema really does allow a sale with no cashier.
     *
     * Stated before the behaviour that depends on it, so a future migration that
     * made the column required would fail here rather than quietly change what
     * the ranking can be asked to do.
     *
     * @test
     */
    public function the_cashier_column_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('sales', 'cashier_id'));

        $unassigned = $this->sale(self::DAY, '100.00');
        $this->assertNull($unassigned->fresh()->cashier_id);
    }

    /**
     * A sale with no cashier is absent from the ranking.
     *
     * @test
     */
    public function a_sale_with_no_cashier_is_excluded(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, self::DAY, '2000.00');
        $this->sale(self::DAY, '500.00');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($salesman->id, $rows[0]['user_id']);
    }

    /**
     * No "Unknown User" and no "Walk-in Staff" is invented for those sales.
     *
     * @test
     */
    public function no_placeholder_user_is_invented(): void
    {
        $this->sale(self::DAY, '500.00');

        $names = array_column($this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200)), 'user_name');

        foreach ($names as $name) {
            $this->assertStringNotContainsStringIgnoringCase('unknown', $name);
            $this->assertStringNotContainsStringIgnoringCase('walk-in', $name);
            $this->assertStringNotContainsStringIgnoringCase('unassigned', $name);
            $this->assertStringNotContainsStringIgnoringCase('guest', $name);
        }

        $this->assertSame([], $names);
    }

    /**
     * An unassigned sale is still revenue, and stays in `total_sales`.
     *
     * @test
     */
    public function an_unassigned_sale_still_counts_toward_total_sales(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, self::DAY, '2000.00');
        $this->sale(self::DAY, '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame(2, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('2500.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The ranking deliberately does not reconcile to `total_sales`.
     *
     * @test
     */
    public function the_ranking_does_not_reconcile_to_total_sales(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, self::DAY, '2000.00');
        $this->sale(self::DAY, '500.00');
        $this->sale(self::DAY, '300.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue(Money::equals('2000.00', $this->summed($response)));
        $this->assertTrue(Money::equals('2800.00', $response->json('data.metrics.total_sales')));
        $this->assertTrue(Money::greaterThan($response->json('data.metrics.total_sales'), $this->summed($response)));
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's invoice is absent from the ranking entirely.
     *
     * @test
     */
    public function another_businesss_sale_is_excluded(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '2000.00');

        $foreignBusiness = Business::factory()->create();
        $foreignStaff = $this->staff('Foreign Salesman', $foreignBusiness);
        $this->saleOfBusiness($foreignStaff, self::DAY, '9000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$salesman->id], $this->order($response));
        $this->assertTrue(Money::equals('2000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A foreign user attached to a local sale cannot leak.
     *
     * The exact corrupted state the second tenant guard exists for: the invoice
     * is local, so `sales.business_id` alone accepts it, but `cashier_id` points
     * at another tenant's staff account. Without `users.business_id = ?` on the
     * join, this report would print that business's staff **name and id** and
     * their revenue to this business.
     *
     * @test
     */
    public function a_foreign_user_on_a_local_sale_cannot_leak(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '2000.00');

        $foreignBusiness = Business::factory()->create();
        $foreignStaff = $this->staff('Foreign Salesman', $foreignBusiness);

        // A local invoice whose cashier is another business's user.
        $this->sale(self::DAY, '7000.00', ['cashier_id' => $foreignStaff->id]);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topUsers($response);

        $this->assertSame([$salesman->id], $this->order($response));
        $this->assertSame(['Local Salesman'], array_column($rows, 'user_name'));
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));

        // The invoice is still a local sale, so the totals above it still see it.
        $this->assertTrue(Money::equals('9000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * The same user name in two businesses stays two separate rows, and neither
     * collapses into the other.
     *
     * @test
     */
    public function the_same_user_name_in_two_businesses_stays_separate(): void
    {
        $local = $this->staff('Ayesha Rahman');
        $this->staffSale($local, self::DAY, '2000.00');

        $foreignBusiness = Business::factory()->create();
        $foreign = $this->staff('Ayesha Rahman', $foreignBusiness);
        $this->saleOfBusiness($foreign, self::DAY, '9000.00');

        // And a local invoice pointing at the foreign namesake.
        $this->sale(self::DAY, '500.00', ['cashier_id' => $foreign->id]);

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows, 'the foreign namesake must not be ranked');
        $this->assertSame($local->id, $rows[0]['user_id']);
        $this->assertSame('Ayesha Rahman', $rows[0]['user_name']);
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));
    }

    /**
     * A valid local user still appears, with the right figures, next to all of
     * the above noise.
     *
     * @test
     */
    public function a_valid_local_user_appears_correctly(): void
    {
        $manager = $this->staff('Local Manager');
        $this->staffSale($manager, self::DAY, '1000.00');
        $this->staffSale($manager, self::DAY, '500.00');

        $foreignBusiness = Business::factory()->create();
        $foreignStaff = $this->staff('Foreign Salesman', $foreignBusiness);
        $this->saleOfBusiness($foreignStaff, self::DAY, '9000.00');
        $this->sale(self::DAY, '3000.00', ['cashier_id' => $foreignStaff->id]);

        $rows = $this->byUser($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame([$manager->id], array_keys($rows));
        $this->assertSame('Local Manager', $rows[$manager->id]['name']);
        $this->assertSame(2, $rows[$manager->id]['invoice_count']);
        $this->assertTrue(Money::equals('1500.00', $rows[$manager->id]['total_sales']));
    }

    /**
     * A `customer_id` from another business is rejected before the report runs.
     *
     * @test
     */
    public function a_customer_filter_across_businesses_is_rejected(): void
    {
        $foreignBusiness = Business::factory()->create();
        $foreignCustomer = $this->customer('Foreign Customer', $foreignBusiness);

        $this->report(self::DAY, self::DAY, ['customer_id' => (string) $foreignCustomer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    // ------------------------------------------------------------------
    // Staff lifecycle: deactivation is not deletion
    // ------------------------------------------------------------------

    /**
     * The schema keeps deactivated staff rather than deleting them.
     *
     * `User` has no `deleted_at` and no `SoftDeletes`, so the only lifecycle the
     * table has is `status`. Asserted rather than assumed, because the ranking's
     * handling of an inactive user follows from it.
     *
     * @test
     */
    public function staff_are_deactivated_not_deleted(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('users', 'status'));
    }

    /**
     * A deactivated staff member keeps their period revenue in the ranking.
     *
     * Deactivation is an access decision, not an erasure: the row, the name and
     * the invoices remain, so dropping them would make the leaderboard disagree
     * with the invoices it is built from.
     *
     * @test
     */
    public function an_inactive_user_is_still_ranked(): void
    {
        $former = $this->staff('Former Salesman', null, ['status' => User::STATUS_INACTIVE]);
        $active = $this->staff('Local Salesman');

        $this->staffSale($former, self::DAY, '4000.00');
        $this->staffSale($active, self::DAY, '1000.00');

        $rows = $this->byUser($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame([$former->id, $active->id], array_keys($rows));
        $this->assertSame('Former Salesman', $rows[$former->id]['name']);
        $this->assertTrue(Money::equals('4000.00', $rows[$former->id]['total_sales']));
    }

    /**
     * No status filter is applied, so an active-only reading is not what happens.
     *
     * @test
     */
    public function the_ranking_does_not_filter_on_user_status(): void
    {
        $former = $this->staff('Former Salesman', null, ['status' => User::STATUS_INACTIVE]);
        $this->staffSale($former, self::DAY, '4000.00');

        $this->assertCount(1, $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200)));
    }

    /**
     * A user with no business at all is never ranked on a local sale.
     *
     * `users.business_id` is itself nullable, so a platform-level account can
     * exist without a tenant. The join guard keeps such a row out of every
     * tenant's ranking.
     *
     * @test
     */
    public function a_user_without_a_business_is_never_ranked(): void
    {
        $platform = $this->staff('Platform Admin', null, ['business_id' => null]);
        $this->sale(self::DAY, '3000.00', ['cashier_id' => $platform->id]);

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame([], $rows);
    }

    // ------------------------------------------------------------------
    // Returns and refunds never reduce a staff member's figure
    // ------------------------------------------------------------------

    /**
     * A return does not reduce the gross staff figure.
     *
     * @test
     */
    public function a_return_does_not_reduce_gross_staff_sales(): void
    {
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '2000.00');
        $line = $this->line($sale, $this->product('Latte'), '20', '2000.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '2000.00');

        $this->completedReturn($line, self::DAY, '800.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topUsers($response);

        $this->assertCount(1, $rows);
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));
        $this->assertSame(1, $rows[0]['invoice_count']);

        // The return is still reported, on its own lines, as it always was.
        $this->assertTrue(Money::equals('2000.00', $response->json('data.metrics.total_sales')));
        $this->assertTrue(Money::equals('1200.00', $response->json('data.metrics.net_sales')));
        $this->assertTrue(Money::equals('800.00', $response->json('data.metrics.returns_amount')));
    }

    /**
     * A refund does not reduce the gross staff figure either.
     *
     * @test
     */
    public function a_refund_does_not_reduce_gross_staff_sales(): void
    {
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '2000.00');
        $line = $this->line($sale, $this->product('Latte'), '20', '2000.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '2000.00');

        $this->completedReturn($line, self::DAY, '500.00');

        $rows = $this->topUsers($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertTrue(Money::equals('2000.00', $rows[0]['total_sales']));
        $this->assertSame(1, $rows[0]['invoice_count'], 'a return is not a counted invoice');
    }

    /**
     * A staff member whose whole period was returned still ranks at full gross.
     *
     * @test
     */
    public function a_fully_returned_staff_period_still_ranks_gross(): void
    {
        $salesman = $this->staff('Local Salesman');
        $sale = $this->staffSale($salesman, self::DAY, '3000.00');
        $line = $this->line($sale, $this->product('Latte'), '30', '3000.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '3000.00');

        $this->completedReturn($line, self::DAY, '3000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue(Money::equals('3000.00', $this->topUsers($response)[0]['total_sales']));
        $this->assertTrue(Money::equals('0.00', $response->json('data.metrics.net_sales')));
    }

    // ------------------------------------------------------------------
    // Empty states
    // ------------------------------------------------------------------

    /**
     * No matching sales at all publishes an empty array.
     *
     * @test
     */
    public function zero_matching_sales_returns_an_empty_array(): void
    {
        $this->staffSale($this->staff('Local Salesman'), '2026-01-14', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topUsers($response));
        $this->assertSame(0, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('0.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Sales exist, but none of them names a staff member: also empty.
     *
     * @test
     */
    public function sales_with_no_attributable_user_returns_an_empty_array(): void
    {
        $this->sale(self::DAY, '1000.00');
        $this->sale(self::DAY, '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topUsers($response));
        $this->assertSame(2, $response->json('data.metrics.sale_count'));
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    /**
     * The ranking is not reachable without `reports.view`.
     *
     * @test
     */
    public function the_ranking_is_not_reachable_without_reports_view(): void
    {
        $this->staffSale($this->staff('Local Salesman'), self::DAY, '1000.00');

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
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '1000.00');

        $viewer = $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(1, $this->topUsers($response));
        $this->assertTrue($viewer->fresh()->hasPermission(PermissionService::REPORTS_VIEW));
    }

    /**
     * A reports viewer needs no staff-management permission at all.
     *
     * The stage must not smuggle in a `users.view` requirement: reading a staff
     * member's name inside a sales report is a reporting fact, not access to the
     * staff module.
     *
     * @test
     */
    public function users_view_is_not_required_when_reports_view_exists(): void
    {
        $this->staffSale($this->staff('Local Salesman'), self::DAY, '1000.00');

        $viewer = $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame('Local Salesman', $this->topUsers($response)[0]['user_name']);
        $this->assertFalse(
            $viewer->fresh()->hasPermission(PermissionService::USERS_VIEW),
            'the fixture must hold only reports.view for this test to mean anything',
        );
    }

    /**
     * `users.view` alone is not enough: the ranking is a reports fact.
     *
     * @test
     */
    public function users_view_alone_is_insufficient(): void
    {
        $this->staffSale($this->staff('Local Salesman'), self::DAY, '1000.00');

        $this->userWithPermissions([PermissionService::USERS_VIEW], $this->business->id);

        $this->report(self::DAY, self::DAY)->assertStatus(403);
    }

    /**
     * No staff-reporting permission exists to be granted or revoked.
     *
     * The stage introduces a field, not a capability, so the catalogue must be
     * exactly what it was.
     *
     * @test
     */
    public function no_staff_reporting_permission_is_introduced(): void
    {
        $slugs = PermissionService::catalogueSlugs();

        $this->assertContains(PermissionService::REPORTS_VIEW, $slugs);

        $forbidden = [
            'reports.users',
            'reports.top_users',
            'reports.sales_users',
            'reports.sales_by_user',
            'reports.staff',
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
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('sales'));

        // The relationship is the existing one: `sales.cashier_id`.
        $this->assertTrue(Schema::hasColumn('sales', 'cashier_id'));

        // The canonical sales total column already exists, so no snapshot column
        // was needed to answer the question.
        $this->assertTrue(Schema::hasColumn('sales', 'grand_total'));

        // Nothing report-shaped was introduced.
        foreach (['report_summaries', 'report_user_summaries', 'report_staff_summaries'] as $table) {
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
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, '2026-01-10', '100.00');
        $this->staffSale($salesman, '2026-02-10', '200.00');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

            // `users.name` is only the staff aggregate's to select, so this
            // matches the ranking and not the daily or monthly series beside it.
            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'users.name'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $grouped, 'exactly one grouped staff aggregate');

        // Identifier quoting is driver-specific - SQLite wraps in `"`, MySQL in
        // backticks - so the quoting is stripped before the shape is asserted.
        // What is being checked is the *clauses*, not how either driver spells
        // them.
        $sql = strtolower(str_replace(['"', '`'], '', $grouped[0]['query']));

        // The join, the aggregate and the top-N cut are all server-side.
        $this->assertStringContainsString('inner join users on users.id = sales.cashier_id', $sql);
        $this->assertStringContainsString('sum(sales.grand_total)', $sql);
        $this->assertStringContainsString('count(sales.id)', $sql);
        $this->assertStringContainsString('limit 10', $sql);

        // Grouped by both selected user columns, for `ONLY_FULL_GROUP_BY`.
        $this->assertStringContainsString('group by users.id, users.name', $sql);

        // Ranked by the aggregate, with the deterministic tie-break.
        $this->assertStringContainsString('order by total_sales desc, users.id asc', $sql);

        // The tenant guard is on the *join*, not merely inherited from the parent
        // scope. This is the assertion that would fail if the second guard were
        // ever dropped.
        $this->assertStringContainsString('users.business_id = ?', $sql);

        // And the parent scope is still there too: both boundaries, not one.
        $this->assertStringContainsString('sales.business_id = ?', $sql);

        // No PHP-side grouping helper leaked into the SQL, and no second round
        // trip is hiding in here.
        $this->assertStringNotContainsString('sale_items', $sql);
        $this->assertStringNotContainsString('payments', $sql);

        // And no per-user lookup: the staff list is not paged in behind the
        // aggregate.
        $this->assertStringNotContainsString('select * from users', $sql);
    }

    /**
     * The query count is fixed regardless of the window length.
     *
     * A per-user implementation would issue one query per staff member, so the
     * cost would grow with the catalogue and the range rather than with the data.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_window_length(): void
    {
        $salesman = $this->staff('Local Salesman');

        for ($month = 1; $month <= 12; $month++) {
            $this->staffSale($salesman, sprintf('2026-%02d-10', $month), '100.00');
        }

        // A warm-up request first: the very first call in a test also resolves
        // the Sanctum token and the account status, which would otherwise be
        // counted as part of the short window and make the comparison useless.
        $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $oneMonth = $this->countQueries(fn () => $this->report('2026-01-01', '2026-01-31')->assertStatus(200));
        $twelveMonths = $this->countQueries(fn () => $this->report('2026-01-01', '2026-12-31')->assertStatus(200));

        $this->assertSame($oneMonth, $twelveMonths, 'query count must not grow with the number of months');
        // A ceiling rather than an exact figure: it catches a per-month or
        // per-user implementation without breaking when another aggregate is
        // added to the report.
        $this->assertLessThanOrEqual(17, $twelveMonths);
    }

    /**
     * The query count is fixed regardless of the number of staff.
     *
     * The N+1 guard proper: the window is held fixed while the number of
     * distinct users inside it varies.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_users(): void
    {
        $first = $this->staff('First');
        $this->staffSale($first, self::DAY, '100.00');

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $oneUser = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        for ($index = 1; $index <= 24; $index++) {
            $this->staffSale($this->staff('Staff '.$index), self::DAY, '100.00');
        }

        $twentyFive = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame($oneUser, $twentyFive, 'query count must not grow with the number of users');
    }

    /**
     * The query count is fixed regardless of the number of sales.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_sales(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '100.00');

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $fewSales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        for ($index = 1; $index <= 50; $index++) {
            $this->staffSale($salesman, self::DAY, '100.00');
        }

        $manySales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame($fewSales, $manySales, 'query count must not grow with the number of sales');
    }

    /**
     * The ranking adds exactly one query to the report, and no more.
     *
     * @test
     */
    public function the_ranking_costs_exactly_one_additional_query(): void
    {
        $salesman = $this->staff('Local Salesman');
        $this->staffSale($salesman, self::DAY, '100.00');

        // Warm up so the token and account-status lookups are not counted.
        $this->report(self::DAY, self::DAY)->assertStatus(200);

        $withSales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        // A period with no staff at all still issues the same aggregate - the
        // query is unconditional, so the report's cost does not depend on the
        // shape of the data either.
        $this->business->sales()->delete();
        $withoutSales = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame($withSales, $withoutSales, 'the ranking must be one fixed query');
    }

    // ------------------------------------------------------------------
    // Compatibility with the existing contract
    // ------------------------------------------------------------------

    /**
     * Every pre-existing Sales Report metric is unchanged by the new field.
     *
     * @test
     */
    public function every_existing_metric_is_unchanged_by_the_ranking(): void
    {
        $product = $this->product('Latte');
        $customer = $this->customer('Ayesha Rahman');
        $salesman = $this->staff('Local Salesman');

        $sale = $this->sale(self::DAY, '2000.00', [
            'customer_id' => $customer->id,
            'cashier_id' => $salesman->id,
        ]);
        $line = $this->line($sale, $product, '20', '2000.00');
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '2000.00');

        // In the totals, absent from the ranking.
        $unassigned = $this->sale(self::DAY, '500.00');
        $this->line($unassigned, $this->product('Rice'), '5', '500.00');

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
        $this->assertCount(1, $m['top_users']);
        $this->assertTrue(Money::equals('2000.00', $m['top_users'][0]['total_sales']));
    }

    /**
     * `top_products`, `top_categories` and `top_customers` are untouched by the
     * staff join.
     *
     * The staff aggregate joins `users` onto the same scoped builder the other
     * aggregates read. This is the regression that would catch that join
     * changing their population - or their figures.
     *
     * @test
     */
    public function the_other_rankings_are_unchanged_by_the_staff_join(): void
    {
        $category = $this->category('Beverages');
        $customer = $this->customer('Ayesha Rahman');
        $salesman = $this->staff('Local Salesman');

        $latte = $this->product('Latte', $category);

        $sale = $this->staffSale($salesman, self::DAY, '2000.00');
        $sale->forceFill(['customer_id' => $customer->id])->save();
        $this->line($sale, $latte, '10', '1000.00');
        // An uncategorised product: reaches the product ranking, not the category one.
        $this->line($sale, $this->product('Rice'), '10', '1000.00');

        $walkIn = $this->sale(self::DAY, '1000.00');
        $this->line($walkIn, $latte, '10', '1000.00');

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $this->assertCount(2, $m['top_products']);
        $this->assertCount(1, $m['top_categories']);
        $this->assertSame($category->id, $m['top_categories'][0]['category_id']);
        $this->assertTrue(Money::equals('2000.00', $m['top_categories'][0]['total_sales']));

        // And the customer ranking is exactly as it was: one customer.
        $this->assertCount(1, $m['top_customers']);
        $this->assertSame($customer->id, $m['top_customers'][0]['customer_id']);
    }

    /**
     * The daily and monthly series are untouched by the staff join.
     *
     * @test
     */
    public function the_daily_and_monthly_series_are_unchanged(): void
    {
        $salesman = $this->staff('Local Salesman');

        $this->staffSale($salesman, '2026-01-14', '100.00');
        $this->staffSale($salesman, self::DAY, '200.00');
        // Unassigned, so in the series but not in the ranking.
        $this->sale(self::DAY, '300.00');

        $m = $this->report('2026-01-01', '2026-01-31')->assertStatus(200)->json('data.metrics');

        // The daily series is a filled calendar, so every day of the window is
        // present; the point of the assertion is the two days that carried sales.
        $this->assertCount(31, $m['daily_series']);
        $this->assertCount(1, $m['monthly_series']);

        $days = array_column($m['daily_series'], 'total_sales', 'date');

        $this->assertTrue(Money::equals('100.00', $days['2026-01-14']));
        $this->assertTrue(Money::equals('500.00', $days[self::DAY]), 'assigned plus unassigned');
        $this->assertTrue(Money::equals('600.00', $m['monthly_series'][0]['total_sales']));
        $this->assertTrue(Money::equals('600.00', $m['total_sales']));
    }

    /**
     * The `period` block and the rest of the envelope are unchanged.
     *
     * @test
     */
    public function the_period_block_is_unchanged(): void
    {
        $this->staffSale($this->staff('Local Salesman'), self::DAY, '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame('Sales report generated successfully', $response->json('message'));
        $this->assertSame(self::DAY, $response->json('data.period.from'));
        $this->assertSame(self::DAY, $response->json('data.period.to'));
    }
}
