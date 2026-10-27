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
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 2 — the **monthly sales series** added to `GET /reports/sales`.
 *
 * `metrics.monthly_series` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **It is the same population as `total_sales` and `daily_series`.** Same
 *    tenant, same `ReportDateRange` window, same `customer_id` / `status` /
 *    `payment_method` filters — because the series is grouped off the very
 *    builder `sales()` aggregates. A monthly chart whose bars disagree with the
 *    total printed above them is the failure this stage exists to prevent, so
 *    several tests assert the two agree exactly.
 *  - **It is gross.** Returns and refunds never reduce a month, exactly as they
 *    never reduce `total_sales`. Monthly *returns* and monthly *net* sales are
 *    separate questions and are not answered here.
 *  - **Every month the window touches is present, partial ends included.** A
 *    range of `2026-01-15 … 2026-04-10` is four months long, and reporting three
 *    of them would describe a period the user never asked for.
 *  - **A month with no sales is `'0.00'`, not a missing row and not `null`.** A
 *    chart axis that skipped quiet months would compress the period and
 *    misreport its shape.
 *  - **An open-ended window returns only the months that exist.** There is no
 *    bounded calendar to span, so no history is invented on the open side.
 *  - **Money stays a string.** `Money::format` runs on every figure, including
 *    the synthesised zeros, so no point is a JSON number and no float touches a
 *    total.
 *  - **The cost does not grow with the range.** One grouped aggregate, whatever
 *    the window length — never a query per month.
 */
class ReportMonthlySalesSeriesTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-01-15';

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

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

    /** A customer of this tenant. */
    private function customer(string $name): Customer
    {
        return Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
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
     * A priced sale line of the given amount, returned in part.
     *
     * Built on the models rather than through the HTTP API so the fixture can be
     * given exact figures; the shape written here is the one the return flow
     * writes — a `unit_cost` snapshot on the line, a `return_date` of its own,
     * and a refund payment carrying the return's id.
     */
    private function completedReturn(Sale $sale, string $date, string $refundAmount): SaleReturn
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '50',
            'created_by' => $this->user->id,
        ]);

        $line = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => '10',
            'unit_price' => '100.00',
            'total' => '1000.00',
            'unit_cost' => '60.00',
        ]);

        $return = SaleReturn::create([
            'business_id' => $this->business->id,
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
            'product_id' => $product->id,
            'quantity' => '2',
            'unit_price' => '100.00',
            'total' => $refundAmount,
        ]);

        if ($refundAmount !== '0.00') {
            Payment::create([
                'business_id' => $this->business->id,
                'sale_return_id' => $return->id,
                'type' => Payment::TYPE_REFUND,
                'payment_method' => Payment::PAYMENT_METHOD_CASH,
                'amount' => $refundAmount,
                'received_by' => $this->user->id,
            ]);
        }

        return $return;
    }

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

    /**
     * The series as `month => total_sales`, exactly as the payload carries it.
     *
     * @return array<string, string>
     */
    private function series(TestResponse $response): array
    {
        return collect($response->json('data.metrics.monthly_series'))
            ->mapWithKeys(fn (array $point): array => [$point['month'] => $point['total_sales']])
            ->all();
    }

    /** The series keys, in order — the axis the chart would draw. */
    private function months(TestResponse $response): array
    {
        return array_keys($this->series($response));
    }

    /** The series summed with bcmath, through the project's own money helper. */
    private function summed(array $series): string
    {
        $total = Money::format(null);
        foreach ($series as $amount) {
            $total = Money::add($total, $amount);
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Shape, and the single-month case
    // ------------------------------------------------------------------

    /**
     * One month of sales is one point, carrying the API's own money string.
     *
     * @test
     */
    public function a_single_month_window_returns_a_single_point(): void
    {
        $this->sale(self::DAY, '1000.00');

        $response = $this->report(self::DAY, '2026-01-20')->assertStatus(200);

        $this->assertSame(
            [['month' => '2026-01', 'total_sales' => '1000.00']],
            $response->json('data.metrics.monthly_series'),
        );
    }

    /**
     * Money arrives as a decimal **string**, never as a JSON number or `null`.
     *
     * @test
     */
    public function monthly_amounts_are_money_strings(): void
    {
        $this->sale(self::DAY, '250.50');

        $point = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics.monthly_series.0');

        $this->assertIsString($point['total_sales']);
        $this->assertSame('250.50', $point['total_sales']);
    }

    // ------------------------------------------------------------------
    // Grouping
    // ------------------------------------------------------------------

    /**
     * Several invoices in one month collapse into a single point.
     *
     * Three invoices of 1,000 / 1,500 / 500 across different days of March must
     * read as one month of 3,000 — a series that emitted three points for one
     * month would plot 3,000 as three different bars and misreport the shape of
     * the period.
     *
     * @test
     */
    public function several_invoices_in_one_month_are_grouped_into_one_point(): void
    {
        $this->sale('2026-03-01', '1000.00');
        $this->sale('2026-03-15', '1500.00');
        $this->sale('2026-03-31', '500.00');

        $this->assertSame(
            ['2026-03' => '3000.00'],
            $this->series($this->report('2026-03-01', '2026-03-31')->assertStatus(200)),
        );
    }

    /**
     * Sales in different months land on different points, in calendar order.
     *
     * The order is part of the contract, not a convenience: an axis a chart draws
     * in arrival order would plot a quarter backwards.
     *
     * @test
     */
    public function sales_in_several_months_are_one_point_each_in_calendar_order(): void
    {
        $this->sale('2026-04-05', '400.00');
        $this->sale('2026-01-10', '100.00');
        $this->sale('2026-02-10', '200.00');

        $this->assertSame(
            ['2026-01' => '100.00', '2026-02' => '200.00', '2026-03' => '0.00', '2026-04' => '400.00'],
            $this->series($this->report('2026-01-01', '2026-04-30')->assertStatus(200)),
        );
    }

    /**
     * The month key is the API's own `'YYYY-MM'` calendar identifier.
     *
     * It is asserted rather than assumed, because the value is what the frontend
     * keeps verbatim: anything else — a date, an integer index, a localised
     * label — would mean the client had to re-derive the month, and re-deriving
     * it is exactly where a timezone bug enters.
     *
     * @test
     */
    public function the_month_key_is_a_year_month_calendar_identifier(): void
    {
        $this->sale('2026-01-15', '100.00');
        $this->sale('2026-12-31', '100.00');

        $keys = $this->months($this->report('2026-01-01', '2026-12-31')->assertStatus(200));

        $this->assertCount(12, $keys);
        $this->assertSame('2026-01', $keys[0]);
        $this->assertSame('2026-12', $keys[11]);
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $key);
        }
    }

    /**
     * The month walk crosses a year boundary without losing or repeating a month.
     *
     * A month sequence built by naive day arithmetic (`+30 days`) drifts across
     * December; one built by string comparison stalls. Both are caught by a
     * December-to-January window, which is the only place they disagree.
     *
     * @test
     */
    public function the_axis_crosses_a_year_boundary_exactly(): void
    {
        $this->sale('2025-12-10', '10.00');
        $this->sale('2026-01-10', '20.00');

        $this->assertSame(
            ['2025-12' => '10.00', '2026-01' => '20.00'],
            $this->series($this->report('2025-12-01', '2026-01-31')->assertStatus(200)),
        );
    }

    // ------------------------------------------------------------------
    // Empty months
    // ------------------------------------------------------------------

    /**
     * A month with no sales is present, and is `'0.00'`.
     *
     * Four months asked for, sales in two of them: the series must still describe
     * four months. A missing point is not a neutral gap here — a chart would read
     * it as "the API stopped reporting", and a client filling the gap itself
     * would be inventing the very figure the report is meant to own.
     *
     * @test
     */
    public function months_without_sales_are_returned_as_zero(): void
    {
        $this->sale('2026-01-10', '1000.00');
        $this->sale('2026-03-10', '500.00');

        $series = $this->series($this->report('2026-01-01', '2026-04-30')->assertStatus(200));

        $this->assertSame([
            '2026-01' => '1000.00',
            '2026-02' => '0.00',
            '2026-03' => '500.00',
            '2026-04' => '0.00',
        ], $series);
    }

    /**
     * A long run of empty months is filled, and stays `'0.00'`.
     *
     * The worst case for a fill-in bug is not one gap but a run of them: a loop
     * that advances the month wrongly (or only ever emits the first and last)
     * still looks correct on a two-month window and drops every middle month on a
     * long one.
     *
     * @test
     */
    public function consecutive_empty_months_are_all_returned(): void
    {
        $this->sale('2026-01-10', '100.00');
        $this->sale('2026-06-10', '200.00');

        $response = $this->report('2026-01-01', '2026-06-30')->assertStatus(200);

        $this->assertSame([
            '2026-01' => '100.00',
            '2026-02' => '0.00',
            '2026-03' => '0.00',
            '2026-04' => '0.00',
            '2026-05' => '0.00',
            '2026-06' => '200.00',
        ], $this->series($response));
    }

    /**
     * A bounded window in which nothing sold at all is an all-zero series.
     *
     * It is not an error, not an empty array and not `null`: the report is
     * answering, and the answer for every month is zero. An empty array here
     * would be indistinguishable from "the server has no monthly report".
     *
     * @test
     */
    public function a_bounded_window_with_no_sales_at_all_is_all_zeros(): void
    {
        $response = $this->report('2026-01-01', '2026-03-31')->assertStatus(200);

        $this->assertSame([
            '2026-01' => '0.00',
            '2026-02' => '0.00',
            '2026-03' => '0.00',
        ], $this->series($response));
        $this->assertSame('0.00', $response->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Partial months
    // ------------------------------------------------------------------

    /**
     * The first month is included even when the window opens mid-month.
     *
     * `2026-01-15 … 2026-04-10` touches four months. Reporting only the months
     * that *start and end* inside the window would return February, March and
     * April — dropping January, the month the sales actually fell in, and
     * describing a period the user never asked about.
     *
     * @test
     */
    public function a_partial_first_month_is_included(): void
    {
        $this->sale('2026-01-15', '1000.00');
        $this->sale('2026-02-10', '2000.00');
        $this->sale('2026-03-10', '3000.00');
        $this->sale('2026-04-10', '4000.00');

        $series = $this->series($this->report('2026-01-15', '2026-04-10')->assertStatus(200));

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_keys($series));
        $this->assertSame('1000.00', $series['2026-01']);
    }

    /**
     * The last month is included even when the window closes mid-month.
     *
     * The mirror of the case above, and the one a `fullMonth()`-style guard drops
     * first: April is present with its ten days of trading rather than omitted
     * because the 30th was never reached.
     *
     * @test
     */
    public function a_partial_last_month_is_included(): void
    {
        $this->sale('2026-03-31', '700.00');
        $this->sale('2026-04-01', '900.00');
        $this->sale('2026-04-10', '300.00');

        $series = $this->series($this->report('2026-03-01', '2026-04-10')->assertStatus(200));

        $this->assertSame(['2026-03', '2026-04'], array_keys($series));
        $this->assertSame('1200.00', $series['2026-04']);
    }

    /**
     * A window inside a single month reports that month, not nothing.
     *
     * The degenerate case of the two above: fifteen days of January is a January
     * window, and an implementation that only emits months lying entirely inside
     * the range would return an empty series for a range that certainly sold.
     *
     * @test
     */
    public function a_window_covering_only_part_of_one_month_reports_that_month(): void
    {
        $this->sale('2026-01-16', '1000.00');

        $this->assertSame(
            ['2026-01' => '1000.00'],
            $this->series($this->report('2026-01-15', '2026-01-20')->assertStatus(200)),
        );
    }

    // ------------------------------------------------------------------
    // Window boundaries
    // ------------------------------------------------------------------

    /**
     * Both boundary days land in the month they belong to.
     *
     * The stated example: `2026-02-28 … 2026-03-01` spans February and March, the
     * 28th belongs to February and the 1st to March. This is the test that would
     * fail if the grouping expression truncated the time component wrongly, or if
     * the fill-in loop rounded a bound to the wrong month.
     *
     * @test
     */
    public function both_window_boundaries_are_included(): void
    {
        $this->sale('2026-02-27', '999.00');
        $this->sale('2026-02-28', '1000.00');
        $this->sale('2026-03-01', '2000.00');

        $this->assertSame(
            ['2026-02' => '1000.00', '2026-03' => '2000.00'],
            $this->series($this->report('2026-02-28', '2026-03-01')->assertStatus(200)),
        );
    }

    /**
     * A sale one day before the window never reaches the series.
     *
     * @test
     */
    public function a_sale_the_day_before_the_window_is_excluded(): void
    {
        $this->sale('2026-01-31', '9999.00');
        $this->sale('2026-02-01', '1000.00');

        // January is outside the window entirely, so it is absent from the axis
        // rather than present at zero — the month walk follows the *requested*
        // range, not the range widened to cover a neighbouring sale.
        $this->assertSame(
            ['2026-02' => '1000.00'],
            $this->series($this->report('2026-02-01', '2026-02-28')->assertStatus(200)),
        );
    }

    /**
     * A sale one day after the window never reaches the series.
     *
     * @test
     */
    public function a_sale_the_day_after_the_window_is_excluded(): void
    {
        $this->sale('2026-02-28', '1000.00');
        $this->sale('2026-03-01', '9999.00');

        $this->assertSame(
            ['2026-02' => '1000.00'],
            $this->series($this->report('2026-02-01', '2026-02-28')->assertStatus(200)),
        );
    }

    /**
     * A month entirely before the window is absent, not zero-filled.
     *
     * Zero-filling outside the window is the mirror error: it invents months the
     * user did not select. The axis is exactly the months the range touches.
     *
     * @test
     */
    public function a_month_before_the_window_is_not_in_the_series(): void
    {
        $this->sale('2025-12-15', '5000.00');
        $this->sale('2026-02-15', '1000.00');

        $this->assertSame(
            ['2026-02' => '1000.00'],
            $this->series($this->report('2026-02-01', '2026-02-28')->assertStatus(200)),
        );
    }

    /**
     * A month entirely after the window is absent too.
     *
     * @test
     */
    public function a_month_after_the_window_is_not_in_the_series(): void
    {
        $this->sale('2026-02-15', '1000.00');
        $this->sale('2026-04-15', '5000.00');

        $this->assertSame(
            ['2026-02' => '1000.00'],
            $this->series($this->report('2026-02-01', '2026-02-28')->assertStatus(200)),
        );
    }

    // ------------------------------------------------------------------
    // Open-ended windows
    // ------------------------------------------------------------------

    /**
     * With no upper bound, only the months that carry sales are returned.
     *
     * There is no calendar to span: inventing an end would mean running months
     * forward to "now" for a business whose last sale was in March. The series is
     * therefore exactly the months that exist, in calendar order — with no zeros
     * for the gap between them, because the gap is unbounded and its length is
     * unknowable.
     *
     * @test
     */
    public function an_open_ended_window_returns_only_the_months_that_exist(): void
    {
        $this->sale('2026-01-10', '100.00');
        $this->sale('2026-03-10', '300.00');

        $response = $this->getJson('/api/v1/reports/sales?date_from=2026-01-01')
            ->assertStatus(200);

        $this->assertSame(
            ['2026-01' => '100.00', '2026-03' => '300.00'],
            $this->series($response),
        );
    }

    /**
     * With no lower bound, the same rule applies on the left.
     *
     * No start month is invented either — not the business's founding date, not
     * the epoch, not a rolling twelve months. The series begins where the data
     * begins.
     *
     * @test
     */
    public function a_window_with_no_lower_bound_does_not_invent_history(): void
    {
        $this->sale('2026-03-10', '300.00');

        $response = $this->getJson('/api/v1/reports/sales?date_to=2026-03-31')
            ->assertStatus(200);

        $this->assertSame(['2026-03' => '300.00'], $this->series($response));
    }

    /**
     * An entirely unbounded report returns the months that exist, and nothing else.
     *
     * @test
     */
    public function a_fully_unbounded_report_returns_only_existing_months(): void
    {
        $this->sale('2025-11-10', '100.00');
        $this->sale('2026-02-10', '200.00');

        $response = $this->getJson('/api/v1/reports/sales')->assertStatus(200);

        $this->assertSame(
            ['2025-11' => '100.00', '2026-02' => '200.00'],
            $this->series($response),
        );
    }

    /**
     * An unbounded window with no sales at all is an empty series, not an error.
     *
     * @test
     */
    public function an_unbounded_report_with_no_sales_returns_an_empty_series(): void
    {
        $response = $this->getJson('/api/v1/reports/sales')->assertStatus(200);

        $this->assertSame([], $response->json('data.metrics.monthly_series'));
        $this->assertSame('0.00', $response->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * The `customer_id` filter applies to the series exactly as it applies to the
     * total.
     *
     * @test
     */
    public function the_customer_filter_applies_to_the_series(): void
    {
        $wanted = $this->customer('Wanted');
        $other = $this->customer('Other');

        $this->sale('2026-01-10', '1000.00', ['customer_id' => $wanted->id]);
        $this->sale('2026-01-20', '700.00', ['customer_id' => $other->id]);

        $report = $this->report('2026-01-01', '2026-01-31', ['customer_id' => $wanted->id])
            ->assertStatus(200);

        $this->assertSame(['2026-01' => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
    }

    /**
     * The `payment_method` filter applies to the series.
     *
     * This filter reaches `payments`, a table with no `business_id` of its own,
     * which is why the series has to be grouped off the already-scoped invoice
     * builder rather than off a fresh join.
     *
     * @test
     */
    public function the_payment_method_filter_applies_to_the_series(): void
    {
        $cash = $this->sale('2026-01-10', '1000.00');
        $this->sale('2026-01-20', '700.00');

        $this->pay($cash, Payment::PAYMENT_METHOD_CASH, '1000.00');

        $report = $this->report('2026-01-01', '2026-01-31', [
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ])->assertStatus(200);

        $this->assertSame(['2026-01' => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
    }

    /**
     * The `status` filter applies to the series.
     *
     * @test
     */
    public function the_status_filter_applies_to_the_series(): void
    {
        $this->sale('2026-01-10', '1000.00');
        $this->sale('2026-01-20', '700.00', ['status' => Sale::STATUS_DRAFT]);

        $report = $this->report('2026-01-01', '2026-01-31', ['status' => Sale::STATUS_DRAFT])
            ->assertStatus(200);

        $this->assertSame(['2026-01' => '700.00'], $this->series($report));
        $this->assertSame('700.00', $report->json('data.metrics.total_sales'));
    }

    /**
     * Combined filters narrow the series to exactly what they narrow the total to.
     *
     * @test
     */
    public function combined_filters_leave_the_series_equal_to_the_aggregate(): void
    {
        $wanted = $this->customer('Wanted');
        $other = $this->customer('Other');

        $kept = $this->sale('2026-01-10', '1000.00', ['customer_id' => $wanted->id]);
        $this->sale('2026-01-15', '500.00', ['customer_id' => $wanted->id]);
        $this->sale('2026-02-05', '900.00', ['customer_id' => $other->id]);
        $this->sale('2026-03-05', '800.00', ['customer_id' => $wanted->id, 'status' => Sale::STATUS_DRAFT]);
        $this->pay($kept, Payment::PAYMENT_METHOD_CARD, '1000.00');

        $report = $this->report('2026-01-01', '2026-03-31', [
            'customer_id' => $wanted->id,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
            'status' => Sale::STATUS_COMPLETED,
        ])->assertStatus(200);

        $this->assertSame([
            '2026-01' => '1000.00',
            '2026-02' => '0.00',
            '2026-03' => '0.00',
        ], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Consistency with the totals and the daily series
    // ------------------------------------------------------------------

    /**
     * The series sums to the aggregate total, to the cent.
     *
     * This is the identity the whole stage rests on, so it is checked with
     * bcmath over several months rather than on a single figure — a grouping bug
     * that loses or duplicates a month changes the sum, and no per-point
     * assertion would notice on its own.
     *
     * @test
     */
    public function the_series_sums_to_the_aggregate_total(): void
    {
        $this->sale('2026-01-10', '1000.10');
        $this->sale('2026-01-20', '2000.20');
        $this->sale('2026-02-10', '3000.30');
        $this->sale('2026-04-10', '4000.40');
        $this->sale('2026-04-20', '5000.05');

        $report = $this->report('2026-01-01', '2026-05-31')->assertStatus(200);

        $sum = $this->summed($this->series($report));
        $total = $report->json('data.metrics.total_sales');

        $this->assertTrue(Money::equals($sum, $total), "series sum {$sum} vs total {$total}");
        $this->assertSame('15001.05', $sum);
    }

    /**
     * The daily series is untouched by this stage and still sums to the total.
     *
     * @test
     */
    public function the_daily_series_is_unchanged_and_still_sums_to_the_total(): void
    {
        $this->sale('2026-01-10', '1000.00');
        $this->sale('2026-01-20', '2000.00');
        $this->sale('2026-02-10', '3000.00');

        $report = $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

        $daily = collect($report->json('data.metrics.daily_series'))
            ->mapWithKeys(fn (array $point): array => [$point['date'] => $point['total_sales']])
            ->all();

        // The daily axis is untouched: same trading days, same money strings, and
        // the quiet days around them still filled as before.
        $this->assertSame('1000.00', $daily['2026-01-10']);
        $this->assertSame('2000.00', $daily['2026-01-20']);
        $this->assertSame('3000.00', $daily['2026-02-10']);
        $this->assertSame('0.00', $daily['2026-01-01']);
        $this->assertSame('0.00', $daily['2026-02-28']);
        $this->assertCount(59, $daily);
        $this->assertTrue(Money::equals(
            $this->summed($daily),
            $report->json('data.metrics.total_sales'),
        ));
    }

    /**
     * Every previously existing metrics field is still present and unchanged.
     *
     * `monthly_series` is additive: the contract with every existing consumer of
     * `GET /reports/sales` — the summary card, and the tests of the stages before
     * this one — is that nothing it reads moves.
     *
     * @test
     */
    public function the_existing_aggregate_fields_are_unchanged(): void
    {
        $this->sale('2026-01-10', '1000.00');

        $metrics = $this->report('2026-01-01', '2026-01-31')->assertStatus(200)->json('data.metrics');

        foreach ([
            'sale_count', 'total_sales', 'subtotal', 'discount', 'tax', 'net_sales',
            'returns_count', 'returns_amount', 'paid_amount', 'due_amount',
            'customer_outstanding', 'payment_breakdown', 'refund_count',
            'refund_amount', 'refund_breakdown', 'net_collected_amount',
            'daily_series', 'monthly_series',
        ] as $key) {
            $this->assertArrayHasKey($key, $metrics);
        }

        $this->assertSame('1000.00', $metrics['total_sales']);
        $this->assertSame('1000.00', $metrics['net_sales']);
        $this->assertSame(0, $metrics['returns_count']);
        $this->assertSame('0.00', $metrics['returns_amount']);
        $this->assertSame('0.00', $metrics['refund_amount']);
        $this->assertSame(0, $metrics['refund_count']);
        $this->assertSame('1000.00', $metrics['net_collected_amount']);
    }

    // ------------------------------------------------------------------
    // Gross, not net
    // ------------------------------------------------------------------

    /**
     * A completed return does not reduce the month it happened in.
     *
     * The series groups `sales.grand_total` and nothing else. Netting the return
     * here would make the monthly bars disagree with the gross total printed
     * above them, and would answer a question — "what did we net in March?" —
     * that this field has not been asked to answer.
     *
     * @test
     */
    public function a_return_does_not_reduce_the_month_it_happened_in(): void
    {
        $sale = $this->sale('2026-03-10', '1000.00');
        $this->completedReturn($sale, '2026-03-20', '200.00');

        $report = $this->report('2026-03-01', '2026-03-31')->assertStatus(200);

        $this->assertSame(['2026-03' => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
        // The reversal is still reported — on its own fields, not by shrinking
        // the series.
        $this->assertSame(1, $report->json('data.metrics.returns_count'));
        $this->assertSame('200.00', $report->json('data.metrics.returns_amount'));
        $this->assertSame('800.00', $report->json('data.metrics.net_sales'));
    }

    /**
     * A refund payment never becomes a month of sales.
     *
     * A refund is a `Payment` with a null `sale_id` and a `sale_return_id`, so it
     * cannot reach an invoice's `grand_total`; but a monthly grouping built over
     * the wrong table would create a month for it. This asserts it does not.
     *
     * @test
     */
    public function a_refund_payment_does_not_appear_as_a_month_of_sales(): void
    {
        $sale = $this->sale('2026-03-10', '1000.00');
        $this->completedReturn($sale, '2026-03-20', '200.00');

        $report = $this->report('2026-01-01', '2026-05-31')->assertStatus(200);

        $this->assertSame([
            '2026-01' => '0.00',
            '2026-02' => '0.00',
            '2026-03' => '1000.00',
            '2026-04' => '0.00',
            '2026-05' => '0.00',
        ], $this->series($report));
        $this->assertSame('200.00', $report->json('data.metrics.refund_amount'));
    }

    /**
     * A return dated in a later month leaves this month alone.
     *
     * @test
     */
    public function a_return_in_a_later_month_does_not_touch_an_earlier_one(): void
    {
        $sale = $this->sale('2026-01-10', '1000.00');
        $this->completedReturn($sale, '2026-04-20', '200.00');

        $this->assertSame(
            ['2026-01' => '1000.00'],
            $this->series($this->report('2026-01-01', '2026-01-31')->assertStatus(200)),
        );
    }

    // ------------------------------------------------------------------
    // Tenancy
    // ------------------------------------------------------------------

    /**
     * Another business's sales never appear, even in the same month.
     *
     * @test
     */
    public function another_businesss_sales_never_appear_in_the_series(): void
    {
        $other = Business::factory()->create();

        $this->sale('2026-02-10', '1000.00');
        Sale::factory()->create([
            'business_id' => $other->id,
            'sale_date' => '2026-02-10',
            'grand_total' => '5000.00',
            'paid_amount' => '5000.00',
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        $report = $this->report('2026-02-01', '2026-02-28')->assertStatus(200);

        $this->assertSame(['2026-02' => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
    }

    /**
     * A foreign sale does not even create a month in this tenant's axis.
     *
     * Zero-filling is built from *this* tenant's requested range, so a foreign
     * month inside the window is a zero here rather than a leaked figure. The
     * axis is the requested calendar; the amounts are this tenant's.
     *
     * @test
     */
    public function a_foreign_sale_does_not_create_a_month_here(): void
    {
        $other = Business::factory()->create();

        Sale::factory()->create([
            'business_id' => $other->id,
            'sale_date' => '2026-06-10',
            'grand_total' => '5000.00',
            'paid_amount' => '5000.00',
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        $report = $this->report('2026-05-01', '2026-07-31')->assertStatus(200);

        $this->assertSame([
            '2026-05' => '0.00',
            '2026-06' => '0.00',
            '2026-07' => '0.00',
        ], $this->series($report));
    }

    /**
     * The series is tenant scoped through the parent invoice when the payment
     * method filter is active.
     *
     * `payments` carries no `business_id` of its own, so this is the case where a
     * wrong join would let a foreign tenant's payment pull a foreign invoice into
     * the series. It must not.
     *
     * @test
     */
    public function the_series_is_tenant_scoped_through_the_parent_invoice_under_the_payment_filter(): void
    {
        $other = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $other->id]);

        $mine = $this->sale('2026-02-10', '1000.00');
        $this->pay($mine, Payment::PAYMENT_METHOD_CARD, '1000.00');

        $foreign = Sale::factory()->create([
            'business_id' => $other->id,
            'sale_date' => '2026-02-10',
            'grand_total' => '5000.00',
            'paid_amount' => '5000.00',
            'due_amount' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        Payment::create([
            'business_id' => $other->id,
            'sale_id' => $foreign->id,
            'type' => Payment::TYPE_PAYMENT,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
            'amount' => '5000.00',
            'received_by' => $otherUser->id,
        ]);

        $report = $this->report('2026-02-01', '2026-02-28', [
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200);

        $this->assertSame(['2026-02' => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    /**
     * The series is behind the same `reports.view` gate as the rest of the
     * report — there is no second route and no looser one.
     *
     * @test
     */
    public function the_series_is_not_reachable_without_reports_view(): void
    {
        $this->sale(self::DAY, '1000.00');

        $this->flushHeaders();
        $this->getJson('/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY)
            ->assertStatus(401);

        $this->actingAsUnprivilegedUser($this->business);
        $this->getJson('/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY)
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Query shape
    // ------------------------------------------------------------------

    /**
     * The series costs a fixed number of queries, whatever the range length.
     *
     * A per-month implementation would issue one query for every month in the
     * window, so a year-long report would be ~12 round trips and the cost would
     * grow with the length of the range rather than with the amount of data.
     * Comparing a one-month window against a twelve-month one is what makes that
     * visible: the counts must be identical.
     *
     * @test
     */
    public function the_series_costs_a_fixed_number_of_queries_regardless_of_the_window_length(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->sale(sprintf('2026-%02d-10', $month), '10.00');
        }

        // A warm-up request first: the very first call in a test also resolves
        // the Sanctum token and the user's account status, which would otherwise
        // be counted as part of the one-month window and make the comparison
        // meaningless.
        $this->report('2026-01-01', '2026-01-31')->assertStatus(200);

        $oneMonth = $this->countQueries(fn () => $this->report('2026-01-01', '2026-01-31')->assertStatus(200));
        $twelveMonths = $this->countQueries(fn () => $this->report('2026-01-01', '2026-12-31')->assertStatus(200));

        $this->assertSame($oneMonth, $twelveMonths, 'query count must not grow with the number of months');
        // A ceiling rather than an exact figure: it catches a per-month
        // implementation (12+ queries) without breaking every time another
        // aggregate is added to the report. Stage 6 added `top_users`, one more
        // grouped aggregate on the same builder, hence 12 -> 13; the assertion
        // above it, that the count does not *vary*, is the real contract.
        $this->assertLessThanOrEqual(13, $twelveMonths);
    }

    /**
     * The month grouping is done by the database, in exactly one aggregate.
     *
     * Proven by inspecting the SQL rather than the row count: an implementation
     * that fetched the month's invoices and summed them in PHP would return the
     * same numbers with the same query count, but would scale with the *size* of
     * the period instead of its length.
     *
     * @test
     */
    public function the_monthly_series_is_one_grouped_aggregate_in_the_database(): void
    {
        $this->sale('2026-01-10', '100.00');
        $this->sale('2026-02-10', '200.00');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report('2026-01-01', '2026-02-28')->assertStatus(200);

            // `sale_month` is the alias only the monthly grouping carries, so
            // this matches the monthly aggregate and not the daily one beside it.
            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'sale_month'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $grouped, 'exactly one grouped monthly aggregate');
        $this->assertStringContainsString('sum(sales.grand_total)', strtolower($grouped[0]['query']));
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
}
