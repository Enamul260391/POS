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
 * Stage 4 — the **sales by category** ranking added to `GET /reports/sales`.
 *
 * `metrics.top_categories` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **It is the same population as `total_sales`.** It is grouped off the very
 *    builder `sales()` aggregates, so tenant, `ReportDateRange` window,
 *    `customer_id`, `status` and `payment_method` apply by the same predicates.
 *    A ranking that disagrees with the total printed above it is the failure
 *    this stage exists to prevent, and several tests assert the two agree.
 *  - **Tenant isolation is stated at every layer.** The invoice scope, the joined
 *    product and the joined category are each pinned to the caller's business,
 *    because `sale_items.product_id` and `products.category_id` are not
 *    themselves tenant-scoped and `category_id` alone must never be trusted.
 *  - **It is gross.** Returns and refunds never reduce a category's figure,
 *    exactly as they never reduce `total_sales`. Net sales per category is a
 *    separate, differently defined question and is not answered here.
 *  - **It is ranked by revenue, not by quantity**, and ties break on
 *    `category_id` ascending — deterministic, so two identical requests cannot
 *    return two different orders.
 *  - **The top N cut is a database `LIMIT`,** applied after grouping and
 *    ordering. Nothing is fetched, sorted or trimmed in PHP.
 *  - **A line with no identifiable category is excluded, never invented.** That
 *    covers both a service line (no product) and an uncategorised product (a
 *    product with `category_id` null): the category table stores no reserved
 *    "Uncategorized" row, so none is fabricated.
 *  - **Money and quantity stay decimal strings,** exactly as the project's own
 *    `Money::format` / `Quantity::format` conventions define them.
 *  - **The cost is one grouped aggregate,** whatever the catalogue size or the
 *    window length — never a query per category.
 */
class ReportTopCategoriesTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day every boundary test is written around. */
    private const DAY = '2026-01-15';

    /**
     * How many categories the report publishes.
     *
     * Mirrors `ReportService::TOP_CATEGORIES_LIMIT`. Asserted rather than assumed:
     * the ranking is a bounded card, and an unbounded one would be a catalogue
     * dump dressed up as a dashboard.
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

    /** A category, of this tenant unless another business is given. */
    private function category(string $name, ?Business $business = null): Category
    {
        return Category::factory()->create([
            'business_id' => ($business ?? $this->business)->id,
            'name' => $name,
        ]);
    }

    /**
     * A product, optionally in a category.
     *
     * `null` is the schema's own default (`ProductFactory` sets `category_id` to
     * null), so the uncategorised case is a real product row rather than a
     * special one.
     */
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

    /** `metrics.top_categories` exactly as the payload carries it. */
    private function topCategories(TestResponse $response): array
    {
        return $response->json('data.metrics.top_categories') ?? [];
    }

    /**
     * The ranking indexed by category id, for readable assertions.
     *
     * @return array<int, array{name: string, quantity: string, total_sales: string}>
     */
    private function byCategory(TestResponse $response): array
    {
        $indexed = [];

        foreach ($this->topCategories($response) as $row) {
            $indexed[$row['category_id']] = [
                'name' => $row['category_name'],
                'quantity' => $row['quantity'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    /** The category ids, in the order the API returned them. */
    private function order(TestResponse $response): array
    {
        return array_column($this->topCategories($response), 'category_id');
    }

    /** The whole ranking summed with bcmath, through the project's money helper. */
    private function summed(TestResponse $response): string
    {
        $total = Money::format(null);

        foreach ($this->topCategories($response) as $row) {
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

    /**
     * `top_products` indexed by product name, for the compatibility assertions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{quantity: string, total_sales: string}>
     */
    private function byProductName(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['product_name']] = [
                'quantity' => $row['quantity'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    /**
     * `top_categories` indexed by category name, for the compatibility assertions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{quantity: string, total_sales: string}>
     */
    private function byCategoryName(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['category_name']] = [
                'quantity' => $row['quantity'],
                'total_sales' => $row['total_sales'],
            ];
        }

        return $indexed;
    }

    // ------------------------------------------------------------------
    // Shape and the single-category case
    // ------------------------------------------------------------------

    /**
     * The field exists, is an array, and carries exactly the contract's keys.
     *
     * @test
     */
    public function top_categories_is_published_on_the_sales_report(): void
    {
        $category = $this->category('Beverages');
        $this->line($this->sale(self::DAY, '1000.00'), $this->product('Latte', $category), '4', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertIsArray($response->json('data.metrics.top_categories'));
        $this->assertCount(1, $this->topCategories($response));
        $this->assertSame(
            ['category_id', 'category_name', 'quantity', 'total_sales'],
            array_keys($this->topCategories($response)[0]),
        );
    }

    /**
     * One category, one line: the row is that line's own figures.
     *
     * @test
     */
    public function a_single_category_is_reported_with_its_own_figures(): void
    {
        $category = $this->category('Beverages');
        $product = $this->product('Latte', $category);
        $this->line($this->sale(self::DAY, '2500.00'), $product, '10', '2500.00');

        $row = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

        $this->assertSame($category->id, $row['category_id']);
        $this->assertSame('Beverages', $row['category_name']);
        $this->assertSame('10', $row['quantity']);
        $this->assertTrue(Money::equals('2500.00', $row['total_sales']));
    }

    /**
     * Distinct categories stay distinct rows — the grouping is real.
     *
     * @test
     */
    public function distinct_categories_are_reported_as_distinct_rows(): void
    {
        $beverages = $this->category('Beverages');
        $grocery = $this->category('Grocery');

        $sale = $this->sale(self::DAY, '1500.00');
        $this->line($sale, $this->product('Latte', $beverages), '5', '500.00');
        $this->line($sale, $this->product('Rice', $grocery), '10', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(2, $this->topCategories($response));
        $this->assertSame([$grocery->id, $beverages->id], $this->order($response));
    }

    /**
     * Two products of one category are one row, not two.
     *
     * This is the case the whole stage turns on: the report groups by *category*,
     * so the ranking is not the product leaderboard with a label swapped.
     *
     * @test
     */
    public function several_products_of_one_category_aggregate_into_one_row(): void
    {
        $category = $this->category('Grocery');

        $sale = $this->sale(self::DAY, '2500.00');
        $this->line($sale, $this->product('Rice', $category), '10', '1000.00');
        $this->line($sale, $this->product('Oil', $category), '5', '1500.00');

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($category->id, $rows[0]['category_id']);
        $this->assertSame('15', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('2500.00', $rows[0]['total_sales']));
    }

    /**
     * Several lines of one category on one invoice are one row too.
     *
     * @test
     */
    public function several_lines_of_one_category_aggregate_into_one_row(): void
    {
        $category = $this->category('Beverages');
        $product = $this->product('Latte', $category);

        $sale = $this->sale(self::DAY, '900.00');
        $this->line($sale, $product, '1', '300.00');
        $this->line($sale, $product, '2', '600.00');

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame('3', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('900.00', $rows[0]['total_sales']));
    }

    /**
     * The same category sold on separate invoices sums across all of them.
     *
     * This is the case a per-invoice implementation gets right on one invoice
     * and wrong on the second, which is why it is asserted explicitly.
     *
     * @test
     */
    public function a_category_aggregates_across_several_invoices(): void
    {
        $category = $this->category('Grocery');
        $product = $this->product('Rice', $category);

        $this->line($this->sale('2026-01-10', '500.00'), $product, '5', '500.00');
        $this->line($this->sale('2026-01-20', '700.00'), $product, '7', '700.00');
        $this->line($this->sale('2026-02-05', '900.00'), $product, '9', '900.00');

        $rows = $this->topCategories($this->report('2026-01-01', '2026-02-28')->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame('21', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('2100.00', $rows[0]['total_sales']));
    }

    // ------------------------------------------------------------------
    // Ranking, ties and the top-N cut
    // ------------------------------------------------------------------

    /**
     * Revenue decides the order, largest first.
     *
     * @test
     */
    public function categories_are_ranked_by_gross_revenue_descending(): void
    {
        $small = $this->category('Small');
        $big = $this->category('Big');
        $medium = $this->category('Medium');

        $sale = $this->sale(self::DAY, '700.00');
        $this->line($sale, $this->product('A', $small), '1', '100.00');
        $this->line($sale, $this->product('B', $big), '1', '400.00');
        $this->line($sale, $this->product('C', $medium), '1', '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$big->id, $medium->id, $small->id], $this->order($response));
    }

    /**
     * Quantity is reported but never ranks.
     *
     * The cheap category moves far more units and must still come second: a
     * quantity leaderboard is a different report, and shipping one here under a
     * "by revenue" heading would mislabel it.
     *
     * @test
     */
    public function ranking_is_by_revenue_and_not_by_quantity(): void
    {
        $cheapMany = $this->category('Cheap Many');
        $priceyFew = $this->category('Pricey Few');

        $sale = $this->sale(self::DAY, '1300.00');
        $this->line($sale, $this->product('A', $cheapMany), '100', '300.00');
        $this->line($sale, $this->product('B', $priceyFew), '2', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$priceyFew->id, $cheapMany->id], $this->order($response));
        $this->assertSame('100', $this->topCategories($response)[1]['quantity']);
    }

    /**
     * Equal revenue is ordered by `category_id` ascending, deterministically.
     *
     * Two categories with identical totals have no natural order, so without an
     * explicit tie-break the database is free to return either first and two
     * identical requests could disagree — which would make the card flicker
     * between refreshes for no reason.
     *
     * @test
     */
    public function equal_revenue_is_ordered_by_category_id_ascending(): void
    {
        $first = $this->category('First');
        $second = $this->category('Second');
        $third = $this->category('Third');

        $sale = $this->sale(self::DAY, '300.00');
        // Inserted in a deliberately non-ascending order, so an insertion-order
        // or alphabetical reading cannot pass by accident.
        $this->line($sale, $this->product('A', $third), '3', '100.00');
        $this->line($sale, $this->product('B', $first), '1', '100.00');
        $this->line($sale, $this->product('C', $second), '2', '100.00');

        $expected = [$first->id, $second->id, $third->id];
        sort($expected);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame($expected, $this->order($response));

        // Repeated identically, the order must not move.
        $again = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame($this->order($response), $this->order($again));
    }

    /**
     * Only the top N categories come back, and they are the best N.
     *
     * Twenty categories each worth a distinct amount, so rank is decidable and a
     * "first N by id" or a PHP-side trim that lost the ordering would both fail.
     *
     * @test
     */
    public function only_the_top_n_categories_are_returned(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->line(
                $this->sale(self::DAY, '10.00'),
                $this->product('Product '.$index, $this->category('Category '.$index)),
                '1',
                $index.'.00',
            );
        }

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertCount(self::LIMIT, $this->topCategories($response));
        // 20.00 down to 11.00 — the ten highest, in descending order.
        $this->assertSame(
            ['20.00', '19.00', '18.00', '17.00', '16.00', '15.00', '14.00', '13.00', '12.00', '11.00'],
            array_column($this->topCategories($response), 'total_sales'),
        );
    }

    // ------------------------------------------------------------------
    // The date window
    // ------------------------------------------------------------------

    /**
     * A category whose sales are all outside the window contributes nothing.
     *
     * @test
     */
    public function categories_sold_outside_the_window_are_excluded(): void
    {
        $inside = $this->category('Inside');
        $before = $this->category('Before');
        $after = $this->category('After');

        $this->line($this->sale('2026-01-14', '100.00'), $this->product('A', $before), '1', '100.00');
        $this->line($this->sale(self::DAY, '200.00'), $this->product('B', $inside), '2', '200.00');
        $this->line($this->sale('2026-01-16', '300.00'), $this->product('C', $after), '3', '300.00');

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertCount(1, $rows);
        $this->assertSame($inside->id, $rows[0]['category_id']);
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
        $category = $this->category('Edge');
        $product = $this->product('Edge', $category);

        $this->line($this->sale('2026-01-14', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-01-15', '100.00'), $product, '1', '100.00');

        $rows = $this->topCategories($this->report('2026-01-15', '2026-01-20')->assertStatus(200));

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
        $category = $this->category('Edge');
        $product = $this->product('Edge', $category);

        $this->line($this->sale('2026-01-20', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-01-21', '100.00'), $product, '1', '100.00');

        $rows = $this->topCategories($this->report('2026-01-01', '2026-01-20')->assertStatus(200));

        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('100.00', $rows[0]['total_sales']));
    }

    /**
     * A sale just past `date_to` is outside the window, and the header total
     * agrees.
     *
     * The explicit "just outside" case on the upper side, so the half-open
     * interval is pinned from both directions rather than one.
     *
     * @test
     */
    public function a_sale_just_past_the_to_boundary_is_outside_the_window(): void
    {
        $category = $this->category('Edge');
        $product = $this->product('Edge', $category);

        $this->line($this->sale('2026-01-20', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-01-21', '900.00'), $product, '9', '900.00');

        $response = $this->report('2026-01-01', '2026-01-20')->assertStatus(200);
        $rows = $this->topCategories($response);

        $this->assertSame('1', $rows[0]['quantity']);
        // The excluded invoice is not counted by the report it belongs to either.
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A window with no sales at all is an empty list, not an error.
     *
     * @test
     */
    public function a_window_with_no_sales_returns_an_empty_ranking(): void
    {
        $response = $this->report('2020-01-01', '2020-01-31')->assertStatus(200);

        $this->assertSame([], $response->json('data.metrics.top_categories'));
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * `customer_id` narrows the ranking to that customer's sale lines.
     *
     * @test
     */
    public function the_customer_filter_narrows_the_ranking(): void
    {
        $mine = $this->customer('Alice');
        $theirs = $this->customer('Bob');

        $beverages = $this->category('Beverages');
        $grocery = $this->category('Grocery');

        $this->line(
            $this->sale(self::DAY, '1000.00', ['customer_id' => $mine->id]),
            $this->product('Latte', $beverages),
            '10',
            '1000.00',
        );
        $this->line(
            $this->sale(self::DAY, '5000.00', ['customer_id' => $theirs->id]),
            $this->product('Rice', $grocery),
            '50',
            '5000.00',
        );

        // Unfiltered, the big figure leads.
        $all = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertSame([$grocery->id, $beverages->id], $this->order($all));

        // Filtered to Alice, only her category may appear.
        $response = $this->report(self::DAY, self::DAY, ['customer_id' => (string) $mine->id])
            ->assertStatus(200);

        $this->assertSame([$beverages->id], $this->order($response));
        $this->assertTrue(Money::equals('1000.00', $this->topCategories($response)[0]['total_sales']));
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * `status` narrows the ranking the same way it narrows the header.
     *
     * @test
     */
    public function the_status_filter_narrows_the_ranking(): void
    {
        $done = $this->category('Completed');
        $draft = $this->category('Draft');

        $this->line($this->sale(self::DAY, '100.00'), $this->product('A', $done), '1', '100.00');
        $this->line(
            $this->sale(self::DAY, '9000.00', ['status' => Sale::STATUS_DRAFT]),
            $this->product('B', $draft),
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
    public function the_payment_method_filter_narrows_the_ranking(): void
    {
        $cash = $this->category('Cash Items');
        $card = $this->category('Card Items');

        $cashSale = $this->sale(self::DAY, '100.00');
        $this->pay($cashSale, Payment::PAYMENT_METHOD_CASH, '100.00');
        $this->line($cashSale, $this->product('A', $cash), '1', '100.00');

        $cardSale = $this->sale(self::DAY, '7000.00');
        $this->pay($cardSale, Payment::PAYMENT_METHOD_CARD, '7000.00');
        $this->line($cardSale, $this->product('B', $card), '70', '7000.00');

        $response = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CASH])
            ->assertStatus(200);

        $this->assertSame([$cash->id], $this->order($response));
        $this->assertTrue(Money::equals('100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * Every filter at once, still one population and one ranking.
     *
     * @test
     */
    public function combined_filters_narrow_the_ranking_together(): void
    {
        $wanted = $this->customer('Alice');
        $category = $this->category('Grocery');
        $product = $this->product('Rice', $category);

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

        $rows = $this->topCategories($response);
        $this->assertSame('4', $rows[0]['quantity'], 'only the fully matching invoice contributes');
        $this->assertTrue(Money::equals('400.00', $rows[0]['total_sales']));
        $this->assertTrue(Money::equals('400.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A filter that matches nothing is an empty ranking, not an error and not a
     * stale one.
     *
     * @test
     */
    public function a_filter_matching_no_sales_returns_an_empty_ranking(): void
    {
        $category = $this->category('Grocery');
        $this->line($this->sale(self::DAY, '1000.00'), $this->product('Rice', $category), '10', '1000.00');

        $response = $this->report(self::DAY, self::DAY, ['status' => Sale::STATUS_DRAFT])
            ->assertStatus(200);

        $this->assertSame([], $this->topCategories($response));
        $this->assertSame(0, $response->json('data.metrics.sale_count'));
        $this->assertTrue(Money::equals('0.00', $response->json('data.metrics.total_sales')));
    }

    // ------------------------------------------------------------------
    // Lines that cannot name a category
    // ------------------------------------------------------------------

    /**
     * A service line creates neither a product row nor a category one.
     *
     * `sale_items.product_id` is nullable by schema (`nullOnDelete`, and a
     * service genuinely has no product). The line's money still counts towards
     * `total_sales`, but there is no category to report it *against* — so it is
     * excluded. Filing it under a placeholder would let a ৳0 row rank beside real
     * categories and take a slot in the top N.
     *
     * @test
     */
    public function a_service_line_never_becomes_a_fake_category(): void
    {
        $category = $this->category('Beverages');

        $sale = $this->sale(self::DAY, '1500.00');
        $this->line($sale, $this->product('Latte', $category), '5', '500.00');
        $this->serviceLine($sale, '1', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        // Exactly one row — the real category, untouched by the service line.
        $this->assertCount(1, $this->topCategories($response));
        $this->assertSame($category->id, $this->topCategories($response)[0]['category_id']);
        $this->assertSame('Beverages', $this->topCategories($response)[0]['category_name']);
        $this->assertSame('5', $this->topCategories($response)[0]['quantity']);
        $this->assertTrue(Money::equals('500.00', $this->topCategories($response)[0]['total_sales']));

        // The service money is in the header total — it simply has no category.
        $this->assertTrue(Money::equals('1500.00', $response->json('data.metrics.total_sales')));
        $this->assertSame(1, $response->json('data.metrics.sale_count'));
    }

    /**
     * An invoice of nothing but service lines yields an empty ranking.
     *
     * @test
     */
    public function an_invoice_of_only_service_lines_yields_an_empty_ranking(): void
    {
        $sale = $this->sale(self::DAY, '400.00');
        $this->serviceLine($sale, '1', '200.00');
        $this->serviceLine($sale, '2', '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topCategories($response));
        $this->assertTrue(Money::equals('400.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * An uncategorised product is excluded rather than filed under a placeholder.
     *
     * `products.category_id` is nullable by schema and `ProductFactory` defaults
     * it to null, so an uncategorised product is an ordinary row — not a broken
     * one. `categories` stores no reserved "Uncategorized" record, so this report
     * invents neither an id nor a name for it: the line has no identifiable
     * category and is dropped by the same inner join that drops a service line.
     *
     * The test asserts the schema behaviour it depends on, so a future migration
     * making `category_id` required would fail loudly here rather than silently
     * change what this report means.
     *
     * @test
     */
    public function an_uncategorised_product_is_excluded_and_no_placeholder_is_invented(): void
    {
        $this->assertTrue(
            Schema::hasColumn('products', 'category_id'),
            'the category relationship this report reads is products.category_id',
        );

        $beverages = $this->category('Beverages');
        $uncategorised = $this->product('Loose Item');

        // The schema really does allow a null category, which is the premise.
        $this->assertNull($uncategorised->category_id);

        $sale = $this->sale(self::DAY, '1500.00');
        $this->line($sale, $this->product('Latte', $beverages), '5', '500.00');
        $this->line($sale, $uncategorised, '10', '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCategories($response);

        $this->assertCount(1, $rows, 'only the identifiable category is ranked');
        $this->assertSame($beverages->id, $rows[0]['category_id']);
        $this->assertTrue(Money::equals('500.00', $rows[0]['total_sales']));

        // No row anywhere in the payload claims to be an uncategorised bucket.
        $this->assertStringNotContainsStringIgnoringCase(
            'uncategor',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        // The money is still in the header total and in the product ranking.
        $this->assertTrue(Money::equals('1500.00', $response->json('data.metrics.total_sales')));
        $this->assertCount(2, $response->json('data.metrics.top_products'));
    }

    /**
     * An invoice of nothing but uncategorised products yields an empty ranking.
     *
     * Sales exist, but none of them can name a category: a legitimate empty
     * result, not an error.
     *
     * @test
     */
    public function an_invoice_of_only_uncategorised_products_yields_an_empty_ranking(): void
    {
        $sale = $this->sale(self::DAY, '1000.00');
        $this->line($sale, $this->product('Loose A'), '5', '500.00');
        $this->line($sale, $this->product('Loose B'), '5', '500.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([], $this->topCategories($response));
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.total_sales')));
        $this->assertSame(1, $response->json('data.metrics.sale_count'));
    }

    /**
     * A soft-deleted product's revenue drops out, by the schema's own lifecycle
     * rule rather than by this stage's choice.
     *
     * @test
     */
    public function a_soft_deleted_product_is_not_ranked_under_its_category(): void
    {
        $gone = $this->category('Discontinued');
        $kept = $this->category('Still Sold');

        $discontinued = $this->product('Discontinued', $gone);
        $this->line($this->sale(self::DAY, '4000.00'), $discontinued, '40', '4000.00');
        $this->line($this->sale(self::DAY, '100.00'), $this->product('Still Sold', $kept), '1', '100.00');

        // The product actually sold under the category is the one removed, so
        // its category drops out for the real reason rather than by accident.
        $discontinued->delete();
        $this->assertSoftDeleted($discontinued);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([$kept->id], $this->order($response));
        // The invoice total is unaffected: only the ranking excludes it.
        $this->assertTrue(Money::equals('4100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A soft-deleted category takes its historical revenue with it.
     *
     * This is the documented lifecycle limitation of the stage, pinned as
     * behaviour: `Category` soft-deletes and `sale_items` stores no category-name
     * snapshot, so a removed category's revenue cannot be reported under a name
     * the server can still read. No historical name is invented, and no
     * lifecycle rule is silently changed to accommodate it.
     *
     * @test
     */
    public function a_soft_deleted_category_drops_out_with_its_historical_revenue(): void
    {
        $removed = $this->category('Seasonal');
        $kept = $this->category('Year Round');

        $this->line($this->sale(self::DAY, '4000.00'), $this->product('A', $removed), '40', '4000.00');
        $this->line($this->sale(self::DAY, '100.00'), $this->product('B', $kept), '1', '100.00');

        $removed->delete();
        $this->assertSoftDeleted($removed);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCategories($response);

        $this->assertSame([$kept->id], $this->order($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Seasonal',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        // The money is untouched everywhere the report still publishes it.
        $this->assertTrue(Money::equals('4100.00', $response->json('data.metrics.total_sales')));
    }

    // ------------------------------------------------------------------
    // Gross: returns and refunds
    // ------------------------------------------------------------------

    /**
     * A return does not reduce the category's gross figure.
     *
     * A ৳1,000 sale with ৳200 returned is still a ৳1,000 line on the invoice, so
     * the ranking reads 1,000 and the report's own `net_sales` / `returns` lines
     * carry the reversal separately. Net sales per category is a different
     * question and is not answered by this field.
     *
     * @test
     */
    public function a_return_does_not_reduce_the_categories_gross_sales(): void
    {
        $category = $this->category('Beverages');

        $sale = $this->sale(self::DAY, '1000.00');
        $line = $this->line($sale, $this->product('Latte', $category), '10', '1000.00');

        $this->completedReturn($line, self::DAY, '200.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        // Gross, unchanged.
        $this->assertTrue(Money::equals('1000.00', $this->topCategories($response)[0]['total_sales']));
        $this->assertSame('10', $this->topCategories($response)[0]['quantity']);

        // The reversal is still published, separately and unchanged.
        $this->assertSame(1, $response->json('data.metrics.returns_count'));
        $this->assertTrue(Money::equals('200.00', $response->json('data.metrics.returns_amount')));
        $this->assertTrue(Money::equals('800.00', $response->json('data.metrics.net_sales')));
    }

    /**
     * A refund payment is neither added nor subtracted from category revenue, and
     * it does not reorder the ranking.
     *
     * Refunds are `payments` rows with a null `sale_id`, so they can never be
     * joined to a sale line — the ranking is built from `sale_items` alone and
     * the money going out cannot appear as negative category revenue.
     *
     * @test
     */
    public function a_refund_does_not_reduce_or_reorder_the_categories_gross_sales(): void
    {
        $small = $this->category('Small');
        $big = $this->category('Big');

        $smallSale = $this->sale(self::DAY, '1000.00');
        $smallLine = $this->line($smallSale, $this->product('A', $small), '10', '1000.00');

        $bigSale = $this->sale(self::DAY, '1200.00');
        $bigLine = $this->line($bigSale, $this->product('B', $big), '12', '1200.00');

        // A refund larger than the smaller category's revenue, to make an
        // order-flipping bug impossible to miss.
        $this->completedReturn($smallLine, self::DAY, '900.00');
        $this->completedReturn($bigLine, self::DAY, '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->byCategory($response);

        $this->assertSame([$big->id, $small->id], $this->order($response), 'order is unchanged');
        $this->assertTrue(Money::equals('1000.00', $rows[$small->id]['total_sales']));
        $this->assertTrue(Money::equals('1200.00', $rows[$big->id]['total_sales']));

        // The refunds are reported on their own lines, never as negative revenue.
        $this->assertSame(2, $response->json('data.metrics.refund_count'));
        $this->assertTrue(Money::equals('1000.00', $response->json('data.metrics.refund_amount')));
        $this->assertTrue(Money::equals('1200.00', $response->json('data.metrics.net_collected_amount')));
    }

    /**
     * The category figures reconcile with `total_sales` when every line is
     * categorised.
     *
     * The ranking and the header total are two readings of the same population,
     * so when every line belongs to a category they must reconcile exactly. This
     * is the check that would catch the two ever drifting apart.
     *
     * @test
     */
    public function category_revenue_reconciles_with_total_sales(): void
    {
        $beverages = $this->category('Beverages');
        $grocery = $this->category('Grocery');
        $personal = $this->category('Personal Care');

        $sale = $this->sale(self::DAY, '3100.00');
        $this->line($sale, $this->product('Latte', $beverages), '10', '1000.00');
        $this->line($sale, $this->product('Rice', $grocery), '20', '1600.00');
        $this->line($sale, $this->product('Shampoo', $personal), '5', '500.00');

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
        $category = $this->category('Beverages');
        $this->line($this->sale(self::DAY, '2500.00'), $this->product('Latte', $category), '10', '2500.00');

        $row = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200))[0];

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
        $half = $this->category('Half');
        $quarter = $this->category('Quarter');
        $whole = $this->category('Whole');

        $sale = $this->sale(self::DAY, '400.00');
        $this->line($sale, $this->product('A', $half), '2.5', '200.00');
        $this->line($sale, $this->product('B', $quarter), '0.25', '100.00');
        $this->line($sale, $this->product('C', $whole), '1', '100.00');

        $rows = $this->byCategory($this->report(self::DAY, self::DAY)->assertStatus(200));

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
        $category = $this->category('By Weight');
        $product = $this->product('Cheese', $category);

        $sale = $this->sale(self::DAY, '300.00');
        $this->line($sale, $product, '0.1', '100.00');
        $this->line($sale, $product, '0.2', '200.00');

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('0.3', $rows[0]['quantity']);
        $this->assertTrue(Money::equals('300.00', $rows[0]['total_sales']));
    }

    /**
     * The category name is the live `categories.name`, not a guess or a stale echo.
     *
     * @test
     */
    public function the_category_name_comes_from_the_category_record(): void
    {
        $category = $this->category('Original Name');
        $this->line($this->sale(self::DAY, '100.00'), $this->product('Latte', $category), '1', '100.00');

        $category->update(['name' => 'Renamed Category']);

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame('Renamed Category', $rows[0]['category_name']);
        $this->assertSame($category->id, $rows[0]['category_id']);
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's invoice never appears, however it is reached.
     *
     * @test
     */
    public function another_businesss_sale_is_excluded(): void
    {
        $mine = $this->category('Mine');

        $foreign = Business::factory()->create();
        $theirCategory = $this->category('Theirs', $foreign);
        $theirProduct = $this->product('Theirs', $theirCategory, $foreign);

        $this->line($this->sale(self::DAY, '100.00'), $this->product('A', $mine), '1', '100.00');
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
     * A foreign product on *this* tenant's invoice cannot leak a foreign
     * category.
     *
     * This is the Stage 3 finding, one hop further: the invoice scope alone
     * would still render "Theirs" from another business, because
     * `sale_items.product_id` is not itself tenant-scoped.
     *
     * @test
     */
    public function another_businesss_product_cannot_leak_a_category_onto_a_local_sale(): void
    {
        $mine = $this->category('Mine');

        $foreign = Business::factory()->create();
        $theirCategory = $this->category('Theirs', $foreign);
        $theirProduct = $this->product('Theirs', $theirCategory, $foreign);

        $this->line($this->sale(self::DAY, '100.00'), $this->product('A', $mine), '1', '100.00');
        // Sold on *this* business's invoice — the only way a foreign product id
        // could ever reach a sale line, since ids are otherwise tenant-scoped.
        $this->line($this->sale(self::DAY, '100.00'), $theirProduct, '1', '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCategories($response);

        $this->assertSame([$mine->id], $this->order($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Theirs',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A foreign category on a local product cannot leak either.
     *
     * `products.category_id` is a plain foreign key with no composite constraint
     * tying it to the product's own business, so this malformed state is
     * technically possible — through a hand-edited row, or any future write path
     * that does not re-validate the category. `category_id` alone is therefore
     * never trusted, and the category is pinned to the caller's tenant on the
     * join itself.
     *
     * @test
     */
    public function another_businesss_category_on_a_local_product_is_excluded(): void
    {
        $mine = $this->category('Mine');

        $foreign = Business::factory()->create();
        $theirCategory = $this->category('Theirs', $foreign);

        $this->line($this->sale(self::DAY, '100.00'), $this->product('A', $mine), '1', '100.00');

        // The product *is* this tenant's, but it points at another tenant's
        // category — the malformed state the third guard exists for.
        $crossTenant = $this->product('Cross Tenant', null);
        $crossTenant->forceFill(['category_id' => $theirCategory->id])->save();

        $this->assertSame($theirCategory->id, $crossTenant->fresh()->category_id);
        $this->assertSame($this->business->id, $crossTenant->business_id);

        $this->line($this->sale(self::DAY, '9000.00'), $crossTenant, '90', '9000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $rows = $this->topCategories($response);

        // The foreign category is dropped, so its revenue is too — the line has
        // no category this tenant may report it under.
        $this->assertSame([$mine->id], $this->order($response));
        $this->assertStringNotContainsStringIgnoringCase(
            'Theirs',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        // The invoice total is unaffected: only the ranking excludes it.
        $this->assertTrue(Money::equals('9100.00', $response->json('data.metrics.total_sales')));
    }

    /**
     * A foreign payment cannot pull a foreign sale or a foreign category in.
     *
     * The payment filter resolves sales through `payments`, so this is the
     * easiest way for a foreign invoice to be dragged in. It must not be: the
     * scoped invoice set is the only door into the ranking.
     *
     * @test
     */
    public function a_foreign_payment_cannot_pull_a_foreign_sale_or_category_into_the_ranking(): void
    {
        $mine = $this->category('Mine');

        $mineSale = $this->sale(self::DAY, '100.00');
        $this->pay($mineSale, Payment::PAYMENT_METHOD_CARD, '100.00');
        $this->line($mineSale, $this->product('A', $mine), '1', '100.00');

        $foreign = Business::factory()->create();
        $theirCategory = $this->category('Theirs', $foreign);
        $theirProduct = $this->product('Theirs', $theirCategory, $foreign);
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
     * Two businesses selling a category of the same name each see only their own.
     *
     * The ranking is keyed by category id, so an identical name across tenants
     * must never merge the two figures into one row — and neither tenant may see
     * the other's row at all.
     *
     * @test
     */
    public function the_same_category_name_in_two_businesses_stays_separate(): void
    {
        $mine = $this->category('Grocery');
        $this->line($this->sale(self::DAY, '100.00'), $this->product('A', $mine), '1', '100.00');

        $foreign = Business::factory()->create();
        $theirs = $this->category('Grocery', $foreign);
        $this->line(
            Sale::factory()->create([
                'business_id' => $foreign->id,
                'sale_date' => self::DAY,
                'grand_total' => '7000.00',
                'status' => Sale::STATUS_COMPLETED,
            ]),
            $this->product('Theirs', $theirs, $foreign),
            '70',
            '7000.00',
        );

        // This tenant's report: one row, this tenant's figures only.
        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topCategories($response));
        $this->assertSame($mine->id, $this->topCategories($response)[0]['category_id']);
        $this->assertTrue(Money::equals('100.00', $this->topCategories($response)[0]['total_sales']));

        // …and the foreign tenant, asking the same question, sees only theirs.
        $theirUser = User::factory()->create(['business_id' => $foreign->id]);
        $this->grantAllCapabilities($theirUser);
        $this->authenticate($theirUser);

        $theirsResponse = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topCategories($theirsResponse));
        $this->assertSame($theirs->id, $this->topCategories($theirsResponse)[0]['category_id']);
        $this->assertTrue(Money::equals('7000.00', $this->topCategories($theirsResponse)[0]['total_sales']));
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
     * No new permission: the ranking sits behind the existing `reports.view`
     * gate, on the existing route, with nothing new to grant.
     *
     * @test
     */
    public function the_ranking_is_not_reachable_without_reports_view(): void
    {
        $category = $this->category('Beverages');
        $this->line($this->sale(self::DAY, '1000.00'), $this->product('Latte', $category), '10', '1000.00');

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
    public function reports_view_alone_authorises_the_ranking(): void
    {
        $category = $this->category('Beverages');
        $this->line($this->sale(self::DAY, '1000.00'), $this->product('Latte', $category), '10', '1000.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);
        $this->assertCount(1, $this->topCategories($response));
    }

    /**
     * No category-reporting permission exists to be granted or revoked.
     *
     * The stage introduces a field, not a capability, so the catalogue must be
     * exactly what it was.
     *
     * @test
     */
    public function no_category_reporting_permission_is_introduced(): void
    {
        $slugs = PermissionService::catalogueSlugs();

        $this->assertContains(PermissionService::REPORTS_VIEW, $slugs);

        $forbidden = [
            'reports.categories',
            'reports.top_categories',
            'reports.sales_categories',
            'reports.sales_by_category',
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
        // The three tables the aggregate joins are the ones the schema already had.
        $this->assertTrue(Schema::hasTable('categories'));
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('sale_items'));

        // The relationship is the existing one, and it is soft-deletable.
        $this->assertTrue(Schema::hasColumn('products', 'category_id'));
        $this->assertTrue(Schema::hasColumn('categories', 'deleted_at'));

        // Nothing report-shaped was introduced: no summary, cache or snapshot
        // table exists for this feature.
        foreach (['report_summaries', 'report_category_summaries', 'report_snapshots'] as $table) {
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
     * that fetched the sale lines and grouped them in PHP would return the same
     * numbers with the same query count, but would scale with the *size* of the
     * period instead of its length.
     *
     * @test
     */
    public function the_ranking_is_one_grouped_aggregate_in_the_database(): void
    {
        $category = $this->category('Grocery');
        $product = $this->product('Rice', $category);

        $this->line($this->sale('2026-01-10', '100.00'), $product, '1', '100.00');
        $this->line($this->sale('2026-02-10', '200.00'), $product, '2', '200.00');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

            // `categories.name` only the category aggregate selects, so this
            // matches the ranking and not the daily or monthly series beside it.
            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'categories.name'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $grouped, 'exactly one grouped category aggregate');

        // Identifier quoting is driver-specific — SQLite wraps in `"`, MySQL in
        // backticks — so the quoting is stripped before the shape is asserted.
        // What is being checked is the *clauses*, not how either driver spells
        // them.
        $sql = strtolower(str_replace(['"', '`'], '', $grouped[0]['query']));

        // The sums, the joins and the top-N cut are all server-side.
        $this->assertStringContainsString('sum(sale_items.total)', $sql);
        $this->assertStringContainsString('sum(sale_items.quantity)', $sql);
        $this->assertStringContainsString('inner join categories on categories.id = products.category_id', $sql);
        $this->assertStringContainsString('inner join products on products.id = sale_items.product_id', $sql);
        $this->assertStringContainsString('limit 10', $sql);

        // Grouped by both selected category columns, for `ONLY_FULL_GROUP_BY`.
        $this->assertStringContainsString('group by categories.id, categories.name', $sql);

        // Ranked by the aggregate, with the deterministic tie-break.
        $this->assertStringContainsString('order by total_sales desc, categories.id asc', $sql);

        // Both tenant guards are in the SQL, not merely in the docs.
        $this->assertStringContainsString('products.business_id = ?', $sql);
        $this->assertStringContainsString('categories.business_id = ?', $sql);

        // The scoped invoice subquery is the third, primary boundary.
        $this->assertStringContainsString(
            'sale_items.sale_id in (select sales.id from sales where sales.business_id = ?',
            $sql,
        );
    }

    /**
     * The query count is fixed regardless of the window length.
     *
     * A per-category implementation would issue one query per category, so the
     * cost would grow with the catalogue and the range rather than with the
     * data. Comparing a one-month window against a year-long one makes that
     * visible: the counts must be identical.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_window_length(): void
    {
        $category = $this->category('Grocery');
        $product = $this->product('Rice', $category);

        for ($month = 1; $month <= 12; $month++) {
            $this->line(
                $this->sale(sprintf('2026-%02d-10', $month), '100.00'),
                $product,
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
        // per-category implementation without breaking when another aggregate is
        // added to the report.
        $this->assertLessThanOrEqual(15, $twelveMonths);
    }

    /**
     * The query count is fixed regardless of how many categories sold.
     *
     * This is the N+1 guard proper: the window is held fixed while the number of
     * distinct categories inside it varies.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_categories(): void
    {
        $sale = $this->sale(self::DAY, '100.00');

        for ($index = 1; $index <= 5; $index++) {
            $this->line($sale, $this->product('Product '.$index, $this->category('Category '.$index)), '1', '10.00');
        }

        $this->report(self::DAY, self::DAY)->assertStatus(200);
        $fiveCategories = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        // Twenty more distinct categories, same window, same invoice.
        for ($index = 6; $index <= 25; $index++) {
            $this->line($sale, $this->product('Product '.$index, $this->category('Category '.$index)), '1', '10.00');
        }

        $twentyFive = $this->countQueries(fn () => $this->report(self::DAY, self::DAY)->assertStatus(200));

        $this->assertSame(
            $fiveCategories,
            $twentyFive,
            'query count must not grow with the number of categories',
        );
    }

    /**
     * The database cuts the ranking before it reaches PHP.
     *
     * The aggregate is bounded by the top N, so the report cannot be answering
     * from a PHP-side sort of the full set: a window with far more categories
     * than the limit still returns exactly the limit.
     *
     * @test
     */
    public function the_database_cuts_the_ranking_before_it_reaches_php(): void
    {
        for ($index = 1; $index <= 40; $index++) {
            $this->line(
                $this->sale(self::DAY, '10.00'),
                $this->product('Product '.$index, $this->category('Category '.$index)),
                '1',
                $index.'.00',
            );
        }

        $rows = $this->topCategories($this->report(self::DAY, self::DAY)->assertStatus(200));

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
     * is asserted here on a report that *does* return categories, so a future
     * edit that let the new aggregate disturb an old one fails loudly.
     *
     * @test
     */
    public function every_existing_metric_is_unchanged_by_the_ranking(): void
    {
        $customer = $this->customer('Alice');
        $category = $this->category('Beverages');

        $sale = $this->sale(self::DAY, '1000.00', [
            'customer_id' => $customer->id,
            // The factory leaves `subtotal` at zero; this invoice has real lines,
            // so the header subtotal is the same 1,000 as its grand total.
            'subtotal' => '1000.00',
        ]);
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '1000.00');
        $line = $this->line($sale, $this->product('Latte', $category), '10', '1000.00');
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
        // different keys — `total_amount` inbound, `refund_amount` outbound — and
        // neither was renamed by this stage.
        $this->assertCount(1, $m['payment_breakdown']);
        $this->assertCount(1, $m['refund_breakdown']);
        $this->assertTrue(Money::equals('1000.00', $m['payment_breakdown'][0]['total_amount']));
        $this->assertTrue(Money::equals('200.00', $m['refund_breakdown'][0]['refund_amount']));

        // The Stage 3 product leaderboard is intact and unrenamed.
        $this->assertCount(1, $m['top_products']);
        $this->assertSame(
            ['product_id', 'product_name', 'quantity', 'total_sales'],
            array_keys($m['top_products'][0]),
        );
    }

    /**
     * `top_products` is exactly the field Stage 3 defined.
     *
     * The category aggregate joins the same tables and the same scoped invoices,
     * so this is the regression that would catch the new join leaking into the
     * existing one.
     *
     * @test
     */
    public function top_products_is_unchanged_by_the_category_ranking(): void
    {
        $beverages = $this->category('Beverages');
        $grocery = $this->category('Grocery');

        // An uncategorised product and a service line: neither can reach the
        // category aggregate, and both must still appear in the product one.
        $sale = $this->sale(self::DAY, '2500.00');
        $this->line($sale, $this->product('Latte', $beverages), '10', '1000.00');
        $this->line($sale, $this->product('Rice', $grocery), '10', '1000.00');
        $this->line($sale, $this->product('Loose Item'), '2', '300.00');
        $this->serviceLine($sale, '1', '200.00');

        $m = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics');

        $products = $this->byProductName($m['top_products']);
        $categories = $this->byCategoryName($m['top_categories']);

        // Three products (not the service line), unchanged in meaning.
        $this->assertCount(3, $m['top_products']);
        $this->assertSame('10', $products['Latte']['quantity']);
        $this->assertTrue(Money::equals('1000.00', $products['Latte']['total_sales']));
        $this->assertSame('10', $products['Rice']['quantity']);
        $this->assertSame('2', $products['Loose Item']['quantity']);
        $this->assertTrue(Money::equals('300.00', $products['Loose Item']['total_sales']));

        // Two categories only: the uncategorised product and the service line
        // contribute to neither the category ranking nor its totals.
        $this->assertCount(2, $m['top_categories']);
        $this->assertTrue(Money::equals('1000.00', $categories['Beverages']['total_sales']));
        $this->assertTrue(Money::equals('1000.00', $categories['Grocery']['total_sales']));
    }

    /**
     * The `period` block and the rest of the envelope are unchanged.
     *
     * @test
     */
    public function the_period_block_is_unchanged(): void
    {
        $category = $this->category('Beverages');
        $this->line($this->sale(self::DAY, '100.00'), $this->product('Latte', $category), '1', '100.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertTrue($response->json('success'));
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
        $category = $this->category('Grocery');
        $product = $this->product('Rice', $category);

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
}
