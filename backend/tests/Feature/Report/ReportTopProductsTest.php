<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
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
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 3 — the **top products** leaderboard added to `GET /reports/sales`.
 *
 * `metrics.top_products` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **It is the same population as `total_sales`.** It is grouped off the very
 *    builder `sales()` aggregates, so tenant, `ReportDateRange` window,
 *    `customer_id`, `status` and `payment_method` apply by the same predicates.
 *    A leaderboard that disagrees with the total printed above it is the failure
 *    this stage exists to prevent, and several tests assert the two agree.
 *  - **It is gross.** Returns and refunds never reduce a product's figure,
 *    exactly as they never reduce `total_sales`. Net product sales is a
 *    separate, differently defined question and is not answered here.
 *  - **It is ranked by revenue, not by quantity**, and ties break on
 *    `product_id` ascending — deterministic, so two identical requests cannot
 *    return two different orders.
 *  - **The top N cut is a database `LIMIT`,** applied after grouping and
 *    ordering. Nothing is fetched, sorted or trimmed in PHP.
 *  - **A line with no product is excluded, never invented.** `product_id` is
 *    nullable for service lines, and a fabricated "Unknown" row would rank
 *    beside real products and take a slot in the top N.
 *  - **Money and quantity stay decimal strings,** exactly as the project's own
 *    `Money::format` / `Quantity::format` conventions define them.
 *  - **The cost is one grouped aggregate,** whatever the catalogue size or the
 *    window length — never a query per product.
 */
class ReportTopProductsTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day every boundary test is written around. */
    private const DAY = '2026-01-15';

    /**
     * How many products the report publishes.
     *
     * Mirrors `ReportService::TOP_PRODUCTS_LIMIT`. Asserted rather than assumed:
     * the leaderboard is a bounded card, and an unbounded one would be a
     * catalogue dump dressed up as a dashboard.
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
     * One invoice of this tenant.
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

    /** A product of this tenant, with an opening stock movement. */
    private function product(string $name): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
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

    /**
     * A sale line with no product at all — a service, or a product that was hard
     * deleted (`sale_items.product_id` is `nullOnDelete`).
     */
    private function serviceLine(Sale $sale, string $quantity, string $total): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'total' => $total,
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

    /** A customer of this tenant. */
    private function customer(string $name = 'Walk-in Customer'): Customer
    {
        return Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
        ]);
    }

    /**
     * A completed return against a sale line, with its refund payment.
     *
     * Built on the models so the fixture can be given exact figures. This is the
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

    /** `metrics.top_products` exactly as the payload carries it. */
    private function topProducts(TestResponse $response): array
    {
        return $response->json('data.metrics.top_products') ?? [];
    }

    /**
     * The leaderboard indexed by product id, for readable assertions.
     *
     * @return array<int, array{name: string, quantity: string, total_sales: string}>
     */
    private function byProduct(TestResponse $response): array
    {
        $indexed = [];

        foreach ($this->topProducts($response) as $row) {
            $indexed[$row['product_id']] = [
                'name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    /** The product ids, in the order the API returned them. */
    private function order(TestResponse $response): array
    {
        return array_column($this->topProducts($response), 'product_id');
    }

    /** The whole leaderboard summed with bcmath, through the project's money helper. */
    private function summed(TestResponse $response): string
    {
        $total = Money::format(null);

        foreach ($this->topProducts($response) as $row) {
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
    // Shape and the single-product case
    // ------------------------------------------------------------------

    /**
     * The field exists, is an array, and carries exactly the contract's keys.
     *
     * @test
     */
    public function top_products_is_published_on_the_sales_report(): void
    {
        $sale = $this->sale(self::DAY, '1000.00');
        $product = $this->product('Latte');
        $this->line($sale, $product, '4', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertIsArray($response->json('data.metrics.top_products'));
        $this->assertCount(1, $this->topProducts($response));
        $this->assertSame(
            ['product_id', 'product_name', 'quantity', 'total_sales'],
            array_keys($this->topProducts($response)[0]),
        );
    }

    /**
     * One product, one line: the row is that line's own figures.
     *
     * @test
     */
    public function a_single_product_is_reported_with_its_own_figures(): void
    {
        $sale = $this->sale(self::DAY, '2500.00');
        $product = $this->product('Latte');
        $this->line($sale, $product, '10', '2500.00');

        $row = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertSame($product->id, $row['product_id']);
        $this->assertSame('Latte', $row['product_name']);
        $this->assertSame('10', $row['quantity']);
        $this->assertTrue(Money::equals('2500.00', $row['total_sales']));
    }

    /**
     * A line's quantity is summed, not overwritten by the last line read.
     *
     * @test
     */
    public function multiple_lines_of_the_same_product_aggregate_into_one_row(): void
    {
        $product = $this->product('Latte');

        $first = $this->sale(self::DAY, '900.00');
        $this->line($first, $product, '1', '300.00');
        $this->line($first, $product, '2', '600.00');

        $second = $this->sale(self::DAY, '400.00');
        $this->line($second, $product, '4', '400.00');

        $rows = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows, 'one product means one row');
        $this->assertSame('7', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('1300.00', $rows[0]['total_sales']));
    }

    /**
     * The same product sold on separate invoices sums across all of them.
     *
     * This is the case a per-invoice implementation gets right on one invoice
     * and wrong on the second, which is why it is asserted explicitly.
     *
     * @test
     */
    public function a_product_aggregates_across_several_invoices(): void
    {
        $product = $this->product('Latte');

        $this->line($this->sale('2026-01-10', '500.00'), $product, '5', '500.00');
        $this->line($this->sale('2026-01-20', '700.00'), $product, '7', '700.00');
        $this->line($this->sale('2026-02-05', '900.00'), $product, '9', '900.00');

        $rows = $this->topProducts($this->report('2026-01-01', '2026-02-28')->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame('21', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('2100.00', $rows[0]['total_sales']));
    }

    /**
     * Distinct products stay distinct rows — the grouping is real.
     *
     * @test
     */
    public function distinct_products_are_reported_as_distinct_rows(): void
    {
        $latte = $this->product('Latte');
        $mocha = $this->product('Mocha');

        $sale = $this->sale(self::DAY, '1500.00');
        $this->line($sale, $latte, '5', '500.00');
        $this->line($sale, $mocha, '10', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(2, $this->topProducts($response));
        $this->assertSame([$mocha->id, $latte->id], $this->order($response));
    }

    // ------------------------------------------------------------------
    // Ranking, ties and the top-N cut
    // ------------------------------------------------------------------

    /**
     * Revenue decides the order, largest first.
     *
     * @test
     */
    public function products_are_ranked_by_gross_revenue_descending(): void
    {
        $small = $this->product('Small');
        $big = $this->product('Big');
        $medium = $this->product('Medium');

        $sale = $this->sale(self::DAY, '700.00');
        $this->line($sale, $small, '1', '100.00');
        $this->line($sale, $big, '1', '400.00');
        $this->line($sale, $medium, '1', '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$big->id, $medium->id, $small->id], $this->order($response));
    }

    /**
     * Quantity is reported but never ranks.
     *
     * The cheap item moves far more units and must still come second: a quantity
     * leaderboard is a different report, and shipping one here under a "best
     * sellers" heading would mislabel it.
     *
     * @test
     */
    public function ranking_is_by_revenue_and_not_by_quantity(): void
    {
        $cheapMany = $this->product('Cheap Many');
        $priceyFew = $this->product('Pricey Few');

        $sale = $this->sale(self::DAY, '1300.00');
        $this->line($sale, $cheapMany, '100', '300.00');
        $this->line($sale, $priceyFew, '2', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$priceyFew->id, $cheapMany->id], $this->order($response));
        $this->assertSame('100', $this->topProducts($response)[1]['quantity']);
    }

    /**
     * Equal revenue is ordered by `product_id` ascending, deterministically.
     *
     * Two products with identical totals have no natural order, so without an
     * explicit tie-break the database is free to return either first and two
     * identical requests could disagree — which would make the card flicker
     * between refreshes for no reason.
     *
     * @test
     */
    public function equal_revenue_is_ordered_by_product_id_ascending(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');
        $third = $this->product('Third');

        $sale = $this->sale(self::DAY, '300.00');
        // Inserted in a deliberately non-ascending order, so an
        // insertion-order or alphabetical reading cannot pass by accident.
        $this->line($sale, $third, '3', '100.00');
        $this->line($sale, $first, '1', '100.00');
        $this->line($sale, $second, '2', '100.00');

        $expected = [$first->id, $second->id, $third->id];
        sort($expected);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame($expected, $this->order($response));

        // Repeated identically, the order must not move.
        $again = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame($this->order($response), $this->order($again));
    }

    /**
     * Only the top N products come back, and they are the best N.
     *
     * Twenty products each worth a distinct amount, so rank is decidable and a
     * "first N by id" or a PHP-side trim that lost the ordering would both fail.
     *
     * @test
     */
    public function only_the_top_n_products_are_returned(): void
    {
        // Twenty products, each worth a distinct amount.
        for ($index = 1; $index <= 20; $index++) {
            $this->line(
                $this->sale(self::DAY, '10.00'),
                $this->product('Product '.$index),
                '1',
                $index.'.00',
            );
        }

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(self::LIMIT, $this->topProducts($response));
        // 20.00 down to 11.00 — the ten highest, in descending order.
        $this->assertSame(
            ['20.00', '19.00', '18.00', '17.00', '16.00', '15.00', '14.00', '13.00', '12.00', '11.00'],
            array_column($this->topProducts($response), 'total_sales'),
        );
    }

    // ------------------------------------------------------------------
    // The date window
    // ------------------------------------------------------------------

    /**
     * A sale dated outside the window contributes nothing.
     *
     * @test
     */
    public function products_sold_outside_the_window_are_excluded(): void
    {
        $in = $this->product('Inside');
        $before = $this->product('Before');
        $after = $this->product('After');

        $this->line($this->sale('2026-01-14', '100.00'), $before, '1', '100.00');
        $this->line($this->sale(self::DAY, '200.00'), $in, '2', '200.00');
        $this->line($this->sale('2026-01-16', '300.00'), $after, '3', '300.00');

        $rows = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($in->id, $rows[0]['product_id']);
    }

    /**
     * `date_from` is an inclusive calendar day.
     *
     * The sale on the lower bound itself belongs to the window. An exclusive
     * lower bound would silently drop the first day of every period — the
     * failure `ReportDateRange` documents and this asserts.
     *
     * @test
     */
    public function a_sale_on_the_from_boundary_is_inside_the_window(): void
    {
        $product = $this->product('Edge');

        $this->line($this->sale('2026-01-14', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-01-15', '100.00'), $product, '1', '100.00');

        $rows = $this->topProducts($this->report('2026-01-15', '2026-01-20')->assertStatus(200));

        // Only the 15th survives; the 14th is outside.
        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('100.00', $rows[0]['total_sales']));
    }

    /**
     * `date_to` is a whole inclusive calendar day, not an instant.
     *
     * The window is applied as a half-open interval ending at the next midnight,
     * so a sale on `date_to` is in and the following day is out.
     *
     * @test
     */
    public function a_sale_on_the_to_boundary_is_inside_the_window(): void
    {
        $product = $this->product('Edge');

        $this->line($this->sale('2026-01-20', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-01-21', '100.00'), $product, '1', '100.00');

        $rows = $this->topProducts($this->report('2026-01-01', '2026-01-20')->assertStatus(200));

        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('100.00', $rows[0]['total_sales']));
    }

    /**
     * A window with no sales at all is an empty list, not an error.
     *
     * @test
     */
    public function a_window_with_no_sales_returns_an_empty_leaderboard(): void
    {
        $response = $this->report('2020-01-01', '2020-01-31')->assertStatus(200);

        $this->assertSame([], $response->json('data.metrics.top_products'));
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * `customer_id` narrows the leaderboard to that customer's sale lines.
     *
     * @test
     */
    public function the_customer_filter_narrows_the_leaderboard(): void
    {
        $mine = $this->customer('Alice');
        $theirs = $this->customer('Bob');

        $latte = $this->product('Latte');
        $mocha = $this->product('Mocha');

        $this->line($this->sale(self::DAY, '1000.00', ['customer_id' => $mine->id]), $latte, '10', '1000.00');
        $this->line($this->sale(self::DAY, '5000.00', ['customer_id' => $theirs->id]), $mocha, '50', '5000.00');

        // Unfiltered, the big figure leads.
        $all = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame([$mocha->id, $latte->id], $this->order($all));

        // Filtered to Alice, only her product may appear.
        $response = $this->report(self::DAY, self::DAY, ['customer_id' => (string) $mine->id])
            ->assertStatus(200);

        $this->assertSame([$latte->id], $this->order($response));
        $this->assertTrue(Money::equals('1000.00', $this->topProducts($response)[0]['total_sales']));
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * `status` narrows the leaderboard the same way it narrows the header.
     *
     * @test
     */
    public function the_status_filter_narrows_the_leaderboard(): void
    {
        $done = $this->product('Completed');
        $draft = $this->product('Draft');

        $this->line($this->sale(self::DAY, '100.00'), $done, '1', '100.00');
        $this->line(
            $this->sale(self::DAY, '9000.00', ['status' => Sale::STATUS_DRAFT]),
            $draft,
            '90',
            '9000.00',
        );

        $response = $this->report(self::DAY, self::DAY, ['status' => Sale::STATUS_COMPLETED])
            ->assertStatus(200);

        $this->assertSame([$done->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * `payment_method` selects the sales population, and the lines follow it.
     *
     * @test
     */
    public function the_payment_method_filter_narrows_the_leaderboard(): void
    {
        $cash = $this->product('Cash Item');
        $card = $this->product('Card Item');

        $cashSale = $this->sale(self::DAY, '100.00');
        $this->pay($cashSale, Payment::PAYMENT_METHOD_CASH, '100.00');
        $this->line($cashSale, $cash, '1', '100.00');

        $cardSale = $this->sale(self::DAY, '7000.00');
        $this->pay($cardSale, Payment::PAYMENT_METHOD_CARD, '7000.00');
        $this->line($cardSale, $card, '70', '7000.00');

        $response = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CASH])
            ->assertStatus(200);

        $this->assertSame([$cash->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Every filter at once, still one population and one leaderboard.
     *
     * @test
     */
    public function combined_filters_narrow_the_leaderboard_together(): void
    {
        $wanted = $this->customer('Alice');
        $product = $this->product('Latte');

        $match = $this->sale(self::DAY, '400.00', ['customer_id' => $wanted->id]);
        $this->pay($match, Payment::PAYMENT_METHOD_CASH, '400.00');
        $this->line($match, $product, '4', '400.00');

        // Same customer and day, but a draft invoice.
        $wrongStatus = $this->sale(self::DAY, '300.00', [
            'customer_id' => $wanted->id,
            'status' => Sale::STATUS_DRAFT,
        ]);
        $this->pay($wrongStatus, Payment::PAYMENT_METHOD_CASH, '300.00');
        $this->line($wrongStatus, $product, '3', '300.00');

        // Right status, wrong customer.
        $wrongCustomer = $this->sale(self::DAY, '200.00', ['customer_id' => $this->customer('Bob')->id]);
        $this->pay($wrongCustomer, Payment::PAYMENT_METHOD_CASH, '200.00');
        $this->line($wrongCustomer, $product, '2', '200.00');

        // Right customer and status, wrong method.
        $wrongMethod = $this->sale(self::DAY, '100.00', ['customer_id' => $wanted->id]);
        $this->pay($wrongMethod, Payment::PAYMENT_METHOD_CARD, '100.00');
        $this->line($wrongMethod, $product, '1', '100.00');

        // Right everything, wrong day.
        $wrongDay = $this->sale('2026-06-01', '50.00', ['customer_id' => $wanted->id]);
        $this->pay($wrongDay, Payment::PAYMENT_METHOD_CASH, '50.00');
        $this->line($wrongDay, $product, '1', '50.00');

        $response = $this->report(self::DAY, self::DAY, [
            'customer_id' => (string) $wanted->id,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
            'status' => Sale::STATUS_COMPLETED,
        ])->assertStatus(200);

        $rows = $this->topProducts($response);
        $this->assertSame('4', $rows[0]['quantity'], 'only the fully matching invoice contributes');
        $this->assertTrue(Money::equals('400.00', $rows[0]['total_sales']));
        $this->assertTrue(Money::equals('400.00', $response->json('data.metrics.total_sales')));
    }

    // ------------------------------------------------------------------
    // Lines without a product
    // ------------------------------------------------------------------

    /**
     * A service line creates no product row and never joins a real one.
     *
     * `sale_items.product_id` is nullable by schema (`nullOnDelete`, and a
     * service genuinely has no product). The line's money still counts towards
     * `total_sales`, but there is no product to report it *against* — so it is
     * excluded. Filing it under a placeholder would let a ৳0 row rank beside
     * real sellers and take a slot in the top N.
     *
     * @test
     */
    public function a_service_line_never_becomes_a_fake_product(): void
    {
        $product = $this->product('Latte');

        $sale = $this->sale(self::DAY, '1500.00');
        $this->line($sale, $product, '5', '500.00');
        $this->serviceLine($sale, '1', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        // Exactly one row — the real product, untouched by the service line.
        $this->assertCount(1, $this->topProducts($response));
        $this->assertSame($product->id, $this->topProducts($response)[0]['product_id']);
        $this->assertSame('Latte', $this->topProducts($response)[0]['product_name']);
        $this->assertSame('5', $this->topProducts($response)[0]['quantity']);
        $this->assertTrue(Money::equals('500.00', $this->topProducts($response)[0]['total_sales']));

        // The service money is in the header total — it simply has no product.
        $this->assertTrue(Money::equals('1500.00', $response->json('data.metrics.total_sales')));
        $this->assertSame(1, $response->json('data.metrics.sale_count'));
    }

    /**
     * An invoice of nothing but service lines yields an empty leaderboard.
     *
     * @test
     */
    public function an_invoice_of_only_service_lines_yields_an_empty_leaderboard(): void
    {
        $sale = $this->sale(self::DAY, '400.00');
        $this->serviceLine($sale, '1', '200.00');
        $this->serviceLine($sale, '2', '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topProducts($response));
        $this->assertTrue(Money::equals('400.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A soft-deleted product's historical revenue drops out, by the schema's
     * own lifecycle rule rather than by this stage's choice.
     *
     * `Product` soft-deletes, so the line keeps its `product_id` and the money
     * stays on the invoice — but `sale_items` holds no name snapshot, and every
     * other read of this relationship in the project (the eager-loaded
     * `saleItems.product`) resolves a deleted product as absent. This report
     * follows that established behaviour rather than inventing a second rule.
     *
     * @test
     */
    public function a_soft_deleted_product_is_not_ranked(): void
    {
        $gone = $this->product('Discontinued');
        $kept = $this->product('Still Sold');

        $this->line($this->sale(self::DAY, '4000.00'), $gone, '40', '4000.00');
        $this->line($this->sale(self::DAY, '100.00'), $kept, '1', '100.00');

        $gone->delete();
        $this->assertSoftDeleted($gone);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$kept->id], $this->order($response));
        // The invoice total is unaffected: only the ranking excludes it.
        $this->assertTrue(Money::equals('4100.00', $response->json('data.metrics.total_sales')));
    }

    // ------------------------------------------------------------------
    // Gross: returns and refunds
    // ------------------------------------------------------------------

    /**
     * A return does not reduce the product's gross figure.
     *
     * A ৳1,000 sale with ৳200 returned is still a ৳1,000 line on the invoice, so
     * the leaderboard reads 1,000 and the report's own `net_sales` / `returns`
     * lines carry the reversal separately. Net product sales is a different
     * question and is not answered by this field.
     *
     * @test
     */
    public function a_return_does_not_reduce_the_products_gross_sales(): void
    {
        $product = $this->product('Latte');

        $sale = $this->sale(self::DAY, '1000.00');
        $line = $this->line($sale, $product, '10', '1000.00');

        $this->completedReturn($line, self::DAY, '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        // Gross, unchanged.
        $this->assertTrue(Money::equals('1000.00', $this->topProducts($response)[0]['total_sales']));
        $this->assertSame('10', $this->topProducts($response)[0]['quantity']);

        // The reversal is still published, separately and unchanged.
        $this->assertSame(1, $response->json('data.metrics.returns_count'));
        $this->assertTrue(Money::equals('200.00', $response->json('data.metrics.returns_amount')));
        $this->assertTrue(Money::equals('800.00', $response->json('data.metrics.net_sales')));
    }

    /**
     * A refund payment is neither added nor subtracted from product revenue.
     *
     * Refunds are `payments` rows with a null `sale_id`, so they can never be
     * joined to a sale line — the leaderboard is built from `sale_items` alone
     * and the money going out cannot appear as negative product revenue.
     *
     * @test
     */
    public function a_refund_does_not_reduce_the_products_gross_sales(): void
    {
        $product = $this->product('Latte');

        $sale = $this->sale(self::DAY, '1000.00');
        $line = $this->line($sale, $product, '10', '1000.00');

        $this->completedReturn($line, self::DAY, '300.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue(Money::equals('1000.00', $this->topProducts($response)[0]['total_sales']));
        $this->assertSame('10', $this->topProducts($response)[0]['quantity']);

        // The refund is reported on its own lines, never as negative revenue.
        $this->assertSame(1, $response->json('data.metrics.refund_count'));
        $this->assertTrue(Money::equals('300.00', $response->json('data.metrics.refund_amount')));
        $this->assertTrue(Money::equals('700.00', $response->json('data.metrics.net_collected_amount')));
    }

    /**
     * The product figures agree with `total_sales`, line for line.
     *
     * The leaderboard and the header total are two readings of the same
     * population, so when every line belongs to a product they must reconcile
     * exactly. This is the check that would catch the two ever drifting apart.
     *
     * @test
     */
    public function product_revenue_reconciles_with_total_sales(): void
    {
        $latte = $this->product('Latte');
        $mocha = $this->product('Mocha');
        $tea = $this->product('Tea');

        $sale = $this->sale(self::DAY, '3100.00');
        $this->line($sale, $latte, '10', '1000.00');
        $this->line($sale, $mocha, '20', '1600.00');
        $this->line($sale, $tea, '5', '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue(Money::equals(
            $response->json('data.metrics.total_sales'),
            $this->summed($response),
        ));
    }

    // ------------------------------------------------------------------
    // Wire format
    // ------------------------------------------------------------------

    /**
     * Money is a string on every row, never a JSON number.
     *
     * @test
     */
    public function money_and_quantity_are_published_as_strings(): void
    {
        $product = $this->product('Latte');
        $this->line($this->sale(self::DAY, '2500.00'), $product, '10', '2500.00');

        $row = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertIsString($row['total_sales']);
        $this->assertIsString($row['quantity']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $row['total_sales']);
        $this->assertSame('2500.00', $row['total_sales']);
    }

    /**
     * A fractional quantity keeps the ledger's four-digit scale.
     *
     * `sale_items.quantity` is `decimal(15,4)`, and `Quantity::format` is what
     * the project uses everywhere else, so 2.5 stays 2.5 and 0.25 stays 0.25 —
     * normalised, never rounded to a whole unit and never a float artefact.
     *
     * @test
     */
    public function quantity_keeps_the_ledger_scale(): void
    {
        $half = $this->product('Half');
        $quarter = $this->product('Quarter');
        $whole = $this->product('Whole');

        $sale = $this->sale(self::DAY, '400.00');
        $this->line($sale, $half, '2.5', '200.00');
        $this->line($sale, $quarter, '0.25', '100.00');
        $this->line($sale, $whole, '1', '100.00');

        $rows = $this->byProduct($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('2.5', $rows[$half->id]['quantity']);
        $this->assertSame('0.25', $rows[$quarter->id]['quantity']);
        // A whole quantity is normalised to no trailing zeros, as everywhere else.
        $this->assertSame('1', $rows[$whole->id]['quantity']);
    }

    /**
     * Fractional quantities sum exactly, with no float drift.
     *
     * 0.1 + 0.2 is the classic case where binary floating point loses the cent:
     * the aggregate is produced by the database and read as a decimal string, so
     * the answer is the exact 0.3 the ledger recorded.
     *
     * @test
     */
    public function fractional_quantities_sum_exactly(): void
    {
        $product = $this->product('By Weight');

        $sale = $this->sale(self::DAY, '300.00');
        $this->line($sale, $product, '0.1', '100.00');
        $this->line($sale, $product, '0.2', '200.00');

        $rows = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('0.3', $rows[0]['quantity']);
    }

    /**
     * The product name is the live `products.name`, not a guess or a stale echo.
     *
     * @test
     */
    public function the_product_name_comes_from_the_product_record(): void
    {
        $product = $this->product('Original Name');
        $this->line($this->sale(self::DAY, '100.00'), $product, '1', '100.00');

        $product->forceFill(['name' => 'Renamed Item'])->save();

        $rows = $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('Renamed Item', $rows[0]['product_name']);
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's invoice contributes nothing.
     *
     * `sale_items` carries no `business_id` of its own, so the tenant boundary
     * is the parent invoice — which is exactly how the header total is scoped.
     * A foreign line is therefore unreachable here by construction.
     *
     * @test
     */
    public function another_businesss_sale_is_excluded(): void
    {
        $mine = $this->product('Mine');

        $foreign = Business::factory()->create();
        $theirProduct = Product::factory()->create([
            'business_id' => $foreign->id,
            'name' => 'Theirs',
        ]);

        $this->line($this->sale(self::DAY, '100.00'), $mine, '1', '100.00');
        $this->line(
            Sale::factory()->create([
                'business_id' => $foreign->id,
                'sale_date' => self::DAY,
                'grand_total' => '9000.00',
                'status' => Sale::STATUS_COMPLETED,
            ]),
            $theirProduct,
            '90',
            '9000.00',
        );

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Another business's product never appears, however it is reached.
     *
     * @test
     */
    public function another_businesss_product_is_excluded(): void
    {
        $foreign = Business::factory()->create();
        $theirProduct = Product::factory()->create([
            'business_id' => $foreign->id,
            'name' => 'Theirs',
        ]);

        // Sold on *this* business's invoice — the only way a foreign product id
        // could ever reach a sale line, since ids are otherwise tenant-scoped.
        // Even then it must not leak a name or revenue into this tenant's report.
        $this->line($this->sale(self::DAY, '100.00'), $theirProduct, '1', '100.00');

        $this->assertSame([], $this->topProducts($this->report(self::DAY, self::DAY)->assertStatus(200)));
    }

    /**
     * A foreign payment cannot pull a foreign sale into this tenant's report.
     *
     * The payment filter resolves sales through `payments`, so this is the
     * easiest way for a foreign invoice to be dragged in. It must not be: the
     * scoped invoice set is the only door into the leaderboard.
     *
     * @test
     */
    public function a_foreign_payment_cannot_pull_a_foreign_sale_into_the_leaderboard(): void
    {
        $mine = $this->product('Mine');

        $mineSale = $this->sale(self::DAY, '100.00');
        $this->pay($mineSale, Payment::PAYMENT_METHOD_CARD, '100.00');
        $this->line($mineSale, $mine, '1', '100.00');

        $foreign = Business::factory()->create();
        $theirProduct = Product::factory()->create(['business_id' => $foreign->id]);
        $theirSale = Sale::factory()->create([
            'business_id' => $foreign->id,
            'sale_date' => self::DAY,
            'grand_total' => '9000.00',
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $this->line($theirSale, $theirProduct, '90', '9000.00');
        Payment::create([
            'business_id' => $foreign->id,
            'sale_id' => $theirSale->id,
            'type' => Payment::TYPE_PAYMENT,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
            'amount' => '9000.00',
        ]);

        $response = $this->report(self::DAY, self::DAY, [
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Two businesses selling a product of the same name each see only their own.
     *
     * The leaderboard is keyed by product id, so an identical name across
     * tenants must never merge the two figures into one row.
     *
     * @test
     */
    public function the_same_product_name_in_two_businesses_stays_separate(): void
    {
        $mine = $this->product('Latte');
        $this->line($this->sale(self::DAY, '100.00'), $mine, '1', '100.00');

        $foreign = Business::factory()->create();
        $theirs = Product::factory()->create([
            'business_id' => $foreign->id,
            'name' => 'Latte',
        ]);
        $this->line(
            Sale::factory()->create([
                'business_id' => $foreign->id,
                'sale_date' => self::DAY,
                'grand_total' => '7000.00',
                'status' => Sale::STATUS_COMPLETED,
            ]),
            $theirs,
            '70',
            '7000.00',
        );

        // This tenant's report: one row, this tenant's figures only.
        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topProducts($response));
        $this->assertTrue(Money::equals('100.00', $this->topProducts($response)[0]['total_sales']));

        // …and the foreign tenant, asking the same question, sees only theirs.
        $theirUser = User::factory()->create(['business_id' => $foreign->id]);
        $this->grantAllCapabilities($theirUser);
        $this->authenticate($theirUser);

        $theirsResponse = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topProducts($theirsResponse));
        $this->assertSame($theirs->id, $this->topProducts($theirsResponse)[0]['product_id']);
        $this->assertTrue(Money::equals('7000.00', $this->topProducts($theirsResponse)[0]['total_sales']));
    }

    /**
     * A foreign `customer_id` cannot be used to pull another tenant's sale in.
     *
     * `ReportFilterRequest` rejects a foreign id at validation; this pins that
     * the filter is not merely advisory.
     *
     * @test
     */
    public function a_customer_filter_across_businesses_is_rejected(): void
    {
        $foreign = Business::factory()->create();
        $theirCustomer = Customer::factory()->create(['business_id' => $foreign->id]);

        $this->report(self::DAY, self::DAY, ['customer_id' => (string) $theirCustomer->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    /**
     * No new permission: the leaderboard sits behind the existing `reports.view`
     * gate, on the existing route, with nothing new to grant.
     *
     * @test
     */
    public function the_leaderboard_is_not_reachable_without_reports_view(): void
    {
        $product = $this->product('Latte');
        $this->line($this->sale(self::DAY, '1000.00'), $product, '10', '1000.00');

        $url = '/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY;

        $this->flushHeaders();
        $this->getJson($url)->assertStatus(401);

        $this->actingAsUnprivilegedUser($this->business);
        $this->getJson($url)->assertStatus(403)->assertJson(['success' => false]);
    }

    /**
     * Holding `reports.view` is enough, and `reports.view` alone is enough.
     *
     * @test
     */
    public function reports_view_alone_authorises_the_leaderboard(): void
    {
        $product = $this->product('Latte');
        $this->line($this->sale(self::DAY, '1000.00'), $product, '10', '1000.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topProducts($response));
    }

    /**
     * No product-reporting permission exists to be granted or revoked.
     *
     * The stage introduces a field, not a capability, so the catalogue must be
     * exactly what it was.
     *
     * @test
     */
    public function no_product_reporting_permission_is_introduced(): void
    {
        $slugs = PermissionService::catalogueSlugs();

        $this->assertContains(PermissionService::REPORTS_VIEW, $slugs);

        foreach (['reports.products', 'reports.top_products', 'reports.sales_products'] as $slug) {
            $this->assertNotContains($slug, $slugs, "{$slug} must not exist");
        }
    }

    // ------------------------------------------------------------------
    // Query shape
    // ------------------------------------------------------------------

    /**
     * The leaderboard is exactly one grouped aggregate in the database.
     *
     * Proven by inspecting the SQL rather than the row count: an implementation
     * that fetched the sale lines and grouped them in PHP would return the same
     * numbers with the same query count, but would scale with the *size* of the
     * period instead of its length.
     *
     * @test
     */
    public function the_leaderboard_is_one_grouped_aggregate_in_the_database(): void
    {
        $product = $this->product('Latte');
        $this->line($this->sale('2026-01-10', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-02-10', '200.00'), $product, '2', '200.00');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

            // `products.name` only the product aggregate selects, so this matches
            // the leaderboard and not the daily or monthly series beside it.
            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'products.name'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $grouped, 'exactly one grouped product aggregate');
        $sql = strtolower($grouped[0]['query']);

        // The sums, the join and the top-N cut are all server-side.
        $this->assertStringContainsString('sum(sale_items.total)', $sql);
        $this->assertStringContainsString('sum(sale_items.quantity)', $sql);
        $this->assertStringContainsString('join', $sql);
        $this->assertStringContainsString('limit', $sql);
    }

    /**
     * The query count is fixed regardless of the window length.
     *
     * A per-product implementation would issue one query per product, so the
     * cost would grow with the catalogue and the range rather than with the
     * data. Comparing a one-month window against a year-long one makes that
     * visible: the counts must be identical.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_window_length(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->line(
                $this->sale(sprintf('2026-%02d-10', $month), '100.00'),
                $this->product('Latte '.$month),
                '1',
                '100.00',
            );
        }

        // A warm-up request first: the very first call in a test also resolves
        // the Sanctum token and the account status, which would otherwise be
        // counted as part of the short window and make the comparison useless.
        $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $oneMonth = $this->countQueries(fn () => $this->report('2026-01-01', '2026-01-31')->assertStatus(200));
        $twelveMonths = $this->countQueries(fn () => $this->report('2026-01-01', '2026-12-31')->assertStatus(200));

        $this->assertSame($oneMonth, $twelveMonths, 'query count must not grow with the number of months');
        // A ceiling rather than an exact figure: it catches a per-month or
        // per-product implementation without breaking when another aggregate is
        // added to the report.
        $this->assertLessThanOrEqual(14, $twelveMonths);
    }

    /**
     * The query count is fixed regardless of how many products sold.
     *
     * This is the N+1 guard proper: the catalogue and the period are held fixed
     * while the number of distinct products inside it varies.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_products(): void
    {
        $sale = $this->sale(self::DAY, '100.00');

        for ($index = 1; $index <= 5; $index++) {
            $this->line($sale, $this->product('Product '.$index), '1', '10.00');
        }

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $fiveProducts = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        // Twenty more distinct products, same window, same invoice.
        for ($index = 6; $index <= 25; $index++) {
            $this->line($sale, $this->product('Product '.$index), '1', '10.00');
        }

        $twentyFive = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(
            $fiveProducts,
            $twentyFive,
            'query count must not grow with the number of products',
        );
    }

    // ------------------------------------------------------------------
    // Compatibility with the existing contract
    // ------------------------------------------------------------------

    /**
     * Adding the leaderboard changed no existing field on the report.
     *
     * This is the regression guard for the whole stage: every pre-existing
     * metric is asserted here on a report that *does* return products, so a
     * future edit that let the new aggregate disturb an old one fails loudly.
     *
     * @test
     */
    public function every_existing_metric_is_unchanged_by_the_leaderboard(): void
    {
        $customer = $this->customer('Alice');
        $product = $this->product('Latte');

        $sale = $this->sale(self::DAY, '1000.00', [
            'customer_id' => $customer->id,
            // The factory leaves `subtotal` at zero; this invoice has real lines,
            // so the header subtotal is the same 1,000 as its grand total.
            'subtotal' => '1000.00',
        ]);
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '1000.00');
        $line = $this->line($sale, $product, '10', '1000.00');
        $this->completedReturn($line, self::DAY, '200.00');

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $this->assertSame(1, $m['sale_count']);
        $this->assertTrue(Money::equals('1000.00', $m['total_sales']));
        $this->assertTrue(Money::equals('1000.00', $m['subtotal']));
        $this->assertTrue(Money::equals('0.00', $m['discount']));
        $this->assertTrue(Money::equals('0.00', $m['tax']));
        $this->assertTrue(Money::equals('800.00', $m['net_sales']));
        $this->assertSame(1, $m['returns_count']);
        $this->assertTrue(Money::equals('200.00', $m['returns_amount']));
        $this->assertTrue(Money::equals('1000.00', $m['paid_amount']));
        $this->assertTrue(Money::equals('0.00', $m['due_amount']));
        $this->assertTrue(Money::equals('0.00', $m['customer_outstanding']));
        $this->assertSame(1, $m['refund_count']);
        $this->assertTrue(Money::equals('200.00', $m['refund_amount']));
        $this->assertTrue(Money::equals('800.00', $m['net_collected_amount']));

        // Both breakdowns survive, with their own rows intact. The two use
        // different keys — `total_amount` inbound, `refund_amount` outbound —
        // and neither was renamed by this stage.
        $this->assertCount(1, $m['payment_breakdown']);
        $this->assertCount(1, $m['refund_breakdown']);
        $this->assertTrue(Money::equals('1000.00', $m['payment_breakdown'][0]['total_amount']));
        $this->assertTrue(Money::equals('200.00', $m['refund_breakdown'][0]['refund_amount']));
    }

    /**
     * `daily_series` and `monthly_series` are untouched.
     *
     * @test
     */
    public function the_daily_and_monthly_series_are_unchanged(): void
    {
        $product = $this->product('Latte');

        $this->line($this->sale('2026-01-15', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-02-20', '200.00'), $product, '2', '200.00');

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
     * The `period` block and the rest of the envelope are unchanged.
     *
     * @test
     */
    public function the_period_block_is_unchanged(): void
    {
        $product = $this->product('Latte');
        $this->line($this->sale(self::DAY, '100.00'), $product, '1', '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame(self::DAY, $response->json('data.period.from'));
        $this->assertSame(self::DAY, $response->json('data.period.to'));
    }
}
