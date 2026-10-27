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
 * Stage 1 — the **daily sales series** added to `GET /reports/sales`.
 *
 * `metrics.daily_series` is the one additive field this stage introduces, and
 * everything it is allowed to be is pinned here:
 *
 *  - **It is the same population as `total_sales`.** Same tenant, same
 *    `ReportDateRange` window, same `customer_id` / `status` / `payment_method`
 *    filters — because the series is built from the very builder `sales()`
 *    aggregates. A chart whose bars disagree with the total printed above them
 *    is the failure this stage exists to prevent, so several tests assert the
 *    two agree exactly.
 *  - **It is gross.** Returns and refunds never reduce a point, exactly as they
 *    never reduce `total_sales`. Net-per-day is a different question and is not
 *    answered here.
 *  - **Both window ends belong to the series**, and a day outside the window can
 *    never appear in it.
 *  - **A day with no sales is `'0.00'`, not a missing row and not `null`.** A
 *    chart axis that silently skipped quiet days would compress the period and
 *    misreport its shape.
 *  - **Money stays a string.** `Money::format` runs on every figure, including
 *    the synthesised zeros, so no point is a JSON number and no float touches a
 *    total.
 */
class ReportDailySalesSeriesTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-10-01';

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
    private function completedReturn(Sale $sale, string $date, string $refundAmount, string $quantity = '2'): SaleReturn
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
            'quantity' => $quantity,
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
     * The series as `date => total_sales`, exactly as the payload carries it.
     *
     * @return array<string, string>
     */
    private function series(TestResponse $response): array
    {
        return collect($response->json('data.metrics.daily_series'))
            ->mapWithKeys(fn (array $point): array => [$point['date'] => $point['total_sales']])
            ->all();
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
    // Shape, and the single-day case
    // ------------------------------------------------------------------

    /**
     * One day of sales is one point, carrying the API's own money string.
     *
     * @test
     */
    public function a_single_day_window_returns_a_single_point(): void
    {
        $this->sale(self::DAY, '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame(
            [['date' => self::DAY, 'total_sales' => '1000.00']],
            $response->json('data.metrics.daily_series'),
        );
    }

    /**
     * Money arrives as a decimal **string**, never as a JSON number or `null`.
     *
     * @test
     */
    public function daily_amounts_are_money_strings(): void
    {
        $this->sale(self::DAY, '250.50');

        $point = $this->report(self::DAY, self::DAY)->assertStatus(200)->json('data.metrics.daily_series.0');

        $this->assertIsString($point['total_sales']);
        $this->assertSame('250.50', $point['total_sales']);
    }

    // ------------------------------------------------------------------
    // Grouping
    // ------------------------------------------------------------------

    /**
     * Several invoices on the same day collapse into one point.
     *
     * Three invoices of 1,000 / 1,500 / 500 on the same `sale_date` must read as
     * a single day of 3,000 — a series that emitted three points for one day
     * would plot 3,000 as three different bars and misreport the shape of the
     * period.
     *
     * @test
     */
    public function several_invoices_on_one_day_are_grouped_into_one_point(): void
    {
        $this->sale(self::DAY, '1000.00');
        $this->sale(self::DAY, '1500.00');
        $this->sale(self::DAY, '500.00');

        $this->assertSame(
            [self::DAY => '3000.00'],
            $this->series($this->report(self::DAY, self::DAY)->assertStatus(200)),
        );
    }

    /**
     * Sales on different days land on different points, in calendar order.
     *
     * The order is part of the contract, not a convenience: an axis a chart draws
     * in arrival order would plot a week backwards.
     *
     * @test
     */
    public function sales_on_several_days_are_one_point_each_in_calendar_order(): void
    {
        $this->sale('2026-10-03', '300.00');
        $this->sale('2026-10-01', '100.00');
        $this->sale('2026-10-02', '200.00');

        $this->assertSame(
            ['2026-10-01' => '100.00', '2026-10-02' => '200.00', '2026-10-03' => '300.00'],
            $this->series($this->report('2026-10-01', '2026-10-03')->assertStatus(200)),
        );
    }

    // ------------------------------------------------------------------
    // Empty days
    // ------------------------------------------------------------------

    /**
     * A day with no sales is present, and is `'0.00'`.
     *
     * Five days asked for, sales on two of them: the series must still describe
     * five days. A missing point is not a neutral gap here — a chart would read
     * it as "the API stopped reporting", and a client filling the gap itself
     * would be inventing the very figure the report is meant to own.
     *
     * @test
     */
    public function days_without_sales_are_returned_as_zero(): void
    {
        $this->sale('2026-10-01', '1000.00');
        $this->sale('2026-10-03', '1500.00');

        $series = $this->series($this->report('2026-10-01', '2026-10-05')->assertStatus(200));

        $this->assertSame([
            '2026-10-01' => '1000.00',
            '2026-10-02' => '0.00',
            '2026-10-03' => '1500.00',
            '2026-10-04' => '0.00',
            '2026-10-05' => '0.00',
        ], $series);
    }

    /**
     * The zero a quiet day gets is the money string, not `0` and not `null`.
     *
     * @test
     */
    public function an_empty_day_is_the_string_zero_not_a_number_or_null(): void
    {
        $this->sale('2026-10-01', '100.00');

        $points = $this->report('2026-10-01', '2026-10-02')->assertStatus(200)->json('data.metrics.daily_series');

        $this->assertCount(2, $points);
        $this->assertIsString($points[1]['total_sales']);
        $this->assertSame('0.00', $points[1]['total_sales']);
    }

    /**
     * A whole window with no sales at all is an all-zero series, not an error.
     *
     * @test
     */
    public function a_window_with_no_sales_is_an_all_zero_series_rather_than_an_error(): void
    {
        $response = $this->report('2026-10-01', '2026-10-03')->assertStatus(200);

        $this->assertSame([
            '2026-10-01' => '0.00',
            '2026-10-02' => '0.00',
            '2026-10-03' => '0.00',
        ], $this->series($response));
        $this->assertSame('0.00', $response->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Window boundaries
    // ------------------------------------------------------------------

    /**
     * Both ends of the window belong to the series.
     *
     * `ReportDateRange` treats `date_from` and `date_to` as inclusive calendar
     * days, and the series inherits that verbatim. Excluding either end would
     * drop a real day's revenue from the chart while the aggregate beside it
     * still counted it — the two would then disagree about the same window.
     *
     * @test
     */
    public function both_window_boundaries_are_included(): void
    {
        $this->sale('2026-09-30', '111.00');
        $this->sale('2026-10-01', '1000.00');
        $this->sale('2026-10-03', '333.00');
        $this->sale('2026-10-04', '444.00');

        $response = $this->report('2026-10-01', '2026-10-03')->assertStatus(200);

        $this->assertSame([
            '2026-10-01' => '1000.00',
            '2026-10-02' => '0.00',
            '2026-10-03' => '333.00',
        ], $this->series($response));
        // The aggregate above it covers the same three days and the same money.
        $this->assertSame('1333.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * The day before `date_from` is outside the series entirely.
     *
     * @test
     */
    public function a_sale_the_day_before_the_window_is_excluded(): void
    {
        $this->sale('2026-09-30', '5000.00');
        $this->sale(self::DAY, '1000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * The day after `date_to` is outside the series entirely.
     *
     * @test
     */
    public function a_sale_the_day_after_the_window_is_excluded(): void
    {
        $this->sale(self::DAY, '1000.00');
        $this->sale('2026-10-02', '7000.00');

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // Existing filters
    // ------------------------------------------------------------------

    /**
     * `customer_id` narrows the series exactly as it narrows the total.
     *
     * Two customers selling on the same day is the case where a chart and its
     * total could silently describe different populations; here they cannot,
     * because the point for the customer asked about excludes the other's money.
     *
     * @test
     */
    public function the_customer_filter_applies_to_the_series(): void
    {
        $wanted = $this->customer('Wanted Customer');
        $other = $this->customer('Other Customer');

        $this->sale(self::DAY, '1000.00', ['customer_id' => $wanted->id]);
        $this->sale(self::DAY, '400.00', ['customer_id' => $other->id]);

        $response = $this->report(self::DAY, self::DAY, ['customer_id' => (string) $wanted->id])
            ->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * `payment_method` applies to the series on the same rule as the total.
     *
     * The existing rule is the header's: restrict to invoices that *have* a
     * payment of that method. An invoice is never partially re-summed by method
     * here — the series states the same figure `total_sales` states, which is
     * exactly why this test asserts the two rather than a per-method total.
     *
     * @test
     */
    public function the_payment_method_filter_applies_to_the_series(): void
    {
        $cash = $this->sale(self::DAY, '1000.00');
        $this->pay($cash, Payment::PAYMENT_METHOD_CASH, '1000.00');

        $card = $this->sale(self::DAY, '700.00');
        $this->pay($card, Payment::PAYMENT_METHOD_CARD, '700.00');

        $response = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CASH])
            ->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * `status` applies to the series on the same rule as the total.
     *
     * @test
     */
    public function the_status_filter_applies_to_the_series(): void
    {
        $this->sale(self::DAY, '1000.00', ['status' => Sale::STATUS_COMPLETED]);
        $this->sale(self::DAY, '900.00', ['status' => Sale::STATUS_DRAFT]);

        $response = $this->report(self::DAY, self::DAY, ['status' => Sale::STATUS_DRAFT])
            ->assertStatus(200);

        $this->assertSame([self::DAY => '900.00'], $this->series($response));
        $this->assertSame('900.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * Every filter at once still leaves the series equal to the aggregate.
     *
     * @test
     */
    public function combined_filters_leave_the_series_equal_to_the_aggregate(): void
    {
        $wanted = $this->customer('Wanted Customer');
        $this->customer('Other Customer');

        $kept = $this->sale(self::DAY, '1000.00', [
            'customer_id' => $wanted->id,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $this->pay($kept, Payment::PAYMENT_METHOD_CARD, '1000.00');

        $dropped = $this->sale(self::DAY, '500.00', [
            'customer_id' => $wanted->id,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $this->pay($dropped, Payment::PAYMENT_METHOD_CASH, '500.00');

        $response = $this->report(self::DAY, self::DAY, [
            'customer_id' => (string) $wanted->id,
            'status' => Sale::STATUS_COMPLETED,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    // ------------------------------------------------------------------
    // The invariant that ties the series to the total
    // ------------------------------------------------------------------

    /**
     * The series sums to `total_sales`, on an irregular window with quiet days.
     *
     * This is the identity that makes the chart trustworthy: the bars and the
     * headline number are two readings of one population, so filling the quiet
     * days with zeroes cannot have moved a cent between them. It is checked with
     * bcmath through `Money::add`, never by adding the JSON values as floats —
     * the amounts deliberately include cents binary floating point cannot hold
     * (`1000.05 + 0.05`).
     *
     * @test
     */
    public function the_series_sums_to_the_aggregate_total(): void
    {
        $this->sale('2026-09-30', '900.00');
        $this->sale('2026-10-01', '1000.05');
        $this->sale('2026-10-01', '0.05');
        $this->sale('2026-10-04', '2499.90');
        $this->sale('2026-10-05', '1.00');
        $this->sale('2026-10-09', '7000.00');

        $response = $this->report('2026-10-01', '2026-10-05')->assertStatus(200);
        $series = $this->series($response);

        $this->assertCount(5, $series, 'every day in the window is represented');
        $this->assertSame('3501.00', $response->json('data.metrics.total_sales'));
        $this->assertSame('3501.00', $this->summed($series));
    }

    /**
     * The existing aggregate fields are untouched by the new field.
     *
     * The stage is additive, so every figure `sales()` published before must
     * still read the same on the same fixture — including the ones that have
     * nothing to do with dates.
     *
     * @test
     */
    public function the_existing_aggregate_fields_are_unchanged(): void
    {
        $sale = $this->sale(self::DAY, '1000.00', [
            'subtotal' => '900.00',
            'discount' => '100.00',
            'tax' => '0.00',
            'paid_amount' => '1000.00',
            'due_amount' => '0.00',
        ]);
        $this->pay($sale, Payment::PAYMENT_METHOD_CASH, '1000.00');

        $this->report(self::DAY, self::DAY)
            ->assertStatus(200)
            ->assertJsonPath('data.period.from', self::DAY)
            ->assertJsonPath('data.period.to', self::DAY)
            ->assertJsonPath('data.metrics.sale_count', 1)
            ->assertJsonPath('data.metrics.total_sales', '1000.00')
            ->assertJsonPath('data.metrics.subtotal', '900.00')
            ->assertJsonPath('data.metrics.discount', '100.00')
            ->assertJsonPath('data.metrics.tax', '0.00')
            ->assertJsonPath('data.metrics.net_sales', '1000.00')
            ->assertJsonPath('data.metrics.returns_count', 0)
            ->assertJsonPath('data.metrics.returns_amount', '0.00')
            ->assertJsonPath('data.metrics.paid_amount', '1000.00')
            ->assertJsonPath('data.metrics.due_amount', '0.00')
            ->assertJsonPath('data.metrics.customer_outstanding', '0.00')
            ->assertJsonPath('data.metrics.refund_count', 0)
            ->assertJsonPath('data.metrics.refund_amount', '0.00')
            ->assertJsonPath('data.metrics.net_collected_amount', '1000.00')
            ->assertJsonCount(1, 'data.metrics.payment_breakdown');
    }

    // ------------------------------------------------------------------
    // Gross: returns and refunds must not touch a point
    // ------------------------------------------------------------------

    /**
     * A return does not reduce any point of the day it happens on.
     *
     * The series is the daily *gross* reading, the same one `total_sales`
     * publishes. If a return were netted into the day it happened, the point
     * would contradict `total_sales` — which is gross and nets nothing — and the
     * day a return happened would look like a bad day of selling.
     *
     * @test
     */
    public function a_return_does_not_reduce_the_day_it_happened_on(): void
    {
        $sale = $this->sale(self::DAY, '1000.00');
        $this->completedReturn($sale, self::DAY, '200.00');

        $report = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
        // The return is still reported, on its own lines — just not in the series.
        $this->assertSame(1, $report->json('data.metrics.returns_count'));
        $this->assertSame('200.00', $report->json('data.metrics.returns_amount'));
        $this->assertSame('800.00', $report->json('data.metrics.net_sales'));
    }

    /**
     * A return dated in a later window does not touch an earlier day's point.
     *
     * @test
     */
    public function a_return_in_a_later_window_does_not_touch_this_window(): void
    {
        $sale = $this->sale(self::DAY, '1000.00');
        $this->completedReturn($sale, '2026-10-05', '300.00');

        $report = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
        $this->assertSame('0.00', $report->json('data.metrics.returns_amount'));
    }

    /**
     * A refund payment does not become a sales point on any day.
     *
     * The series groups `sales.grand_total` over the sales population, so a
     * `Payment` of type `refund` — which carries no `sale_id` and belongs to a
     * return — cannot reach it at all, even dated inside the window.
     *
     * @test
     */
    public function a_refund_payment_does_not_appear_as_a_sales_point(): void
    {
        $sale = $this->sale(self::DAY, '1000.00');
        $this->completedReturn($sale, self::DAY, '400.00');

        $report = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($report));
        $this->assertSame('1000.00', $report->json('data.metrics.total_sales'));
        // The refund is still visible — as money out, never as money in.
        $this->assertSame('400.00', $report->json('data.metrics.refund_amount'));
        $this->assertSame('600.00', $report->json('data.metrics.net_collected_amount'));
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's sales never appear in the series, however large.
     *
     * The foreign invoice is dated inside the window on purpose: this is not
     * about the date range catching it, it is about the tenant scope. A series
     * built from an unscoped `Sale::query()` would show 5,000 of someone else's
     * revenue as a bar under this business's name.
     *
     * @test
     */
    public function another_businesss_sales_never_appear_in_the_series(): void
    {
        $this->sale(self::DAY, '1000.00');

        Sale::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'sale_date' => self::DAY,
            'grand_total' => '5000.00',
            'status' => Sale::STATUS_COMPLETED,
        ]);

        $response = $this->report(self::DAY, self::DAY)->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($response));
        $this->assertSame('1000.00', $response->json('data.metrics.total_sales'));
    }

    /**
     * The series is tenant-scoped through the parent invoice even when the
     * `payment_method` filter makes the query reach `payments`.
     *
     * `payments` has no `business_id` column, so a payment-based scope is a
     * place a cross-tenant aggregate could be built by accident. The foreign card
     * payment below belongs to an invoice this tenant cannot see; if the filter
     * resolved `payments` without the tenant-scoped invoice set, this series
     * would show the foreign invoice's 5,000 under this business's name.
     *
     * @test
     */
    public function the_series_is_tenant_scoped_through_the_parent_invoice(): void
    {
        $mine = $this->sale(self::DAY, '1000.00');
        $this->pay($mine, Payment::PAYMENT_METHOD_CARD, '1000.00');

        $theirs = Sale::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'sale_date' => self::DAY,
            'grand_total' => '5000.00',
            'status' => Sale::STATUS_COMPLETED,
        ]);
        Payment::create([
            'business_id' => $theirs->business_id,
            'sale_id' => $theirs->id,
            'type' => Payment::TYPE_PAYMENT,
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
            'amount' => '5000.00',
        ]);

        $report = $this->report(self::DAY, self::DAY, ['payment_method' => Payment::PAYMENT_METHOD_CARD])
            ->assertStatus(200);

        $this->assertSame([self::DAY => '1000.00'], $this->series($report));
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
     * The series costs a fixed number of queries, whatever the window length.
     *
     * A per-day implementation would issue a query for every calendar day in the
     * window, so a month-long report would be ~31 round trips and the cost would
     * grow with the length of the range rather than with the amount of data.
     * Comparing a one-day window against a thirty-one-day one is what makes that
     * visible: the counts must be identical.
     *
     * @test
     */
    public function the_series_costs_a_fixed_number_of_queries_regardless_of_the_window_length(): void
    {
        for ($day = 1; $day <= 31; $day++) {
            $this->sale(sprintf('2026-10-%02d', $day), '10.00');
        }

        // A warm-up request first: the very first call in a test also resolves
        // the Sanctum token and the user's account status, which would otherwise
        // be counted as part of the one-day window and make the comparison
        // meaningless.
        $this->report('2026-10-01', '2026-10-01')->assertStatus(200);

        $oneDay = $this->countQueries(fn () => $this->report('2026-10-01', '2026-10-01')->assertStatus(200));
        $oneMonth = $this->countQueries(fn () => $this->report('2026-10-01', '2026-10-31')->assertStatus(200));

        $this->assertSame($oneDay, $oneMonth, 'query count must not grow with the number of days');
        // A ceiling rather than an exact figure: it catches a per-day
        // implementation (31+ queries) without breaking every time another
        // aggregate is added to the report. Stage 6 added `top_users`, one more
        // grouped aggregate on the same builder, hence 12 -> 13; the assertion
        // above it, that the count does not *vary*, is the real contract.
        $this->assertLessThanOrEqual(13, $oneMonth);
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
