<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ReportDateRange;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — the reporting **date policy**.
 *
 * `date_from` and `date_to` are calendar days and both ends of the window belong
 * to it. The predicate that enforces that is the one thing every report shares,
 * so it is tested here on its own against a real `DATETIME` column rather than
 * only through an endpoint whose own columns happen to be `DATE`.
 *
 * The boundary rows below are the whole contract:
 *
 *     00:00:00        the first instant of `to`     -> included
 *     midday          the middle of the day          -> included
 *     23:59:59        the last second of `to`       -> included
 *     next 00:00:00   the first instant after `to`   -> excluded
 *
 * A report whose window drops the first or the last of those is not "slightly
 * off" — it silently under-reports the busiest day of the period, which is
 * exactly the day anybody opens a POS report to check.
 */
class ReportDateRangeTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();

        // A real datetime column, so the predicate is exercised against the case
        // it exists for rather than against a DATE column that would pass by
        // luck.
        Schema::create('report_boundary_probe', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
        });
    }

    /**
     * Insert one probe row and report whether the window selects it.
     */
    private function withinWindow(string $occurredAt): bool
    {
        DB::table('report_boundary_probe')->insert(['occurred_at' => $occurredAt]);

        $query = DB::table('report_boundary_probe');
        ReportDateRange::fromFilters(['date_from' => self::DAY, 'date_to' => self::DAY])
            ->apply($query, 'occurred_at');

        return $query->exists();
    }

    /**
     * Every probe row the window selects, oldest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function selected(array $filters): array
    {
        $query = DB::table('report_boundary_probe');
        ReportDateRange::fromFilters($filters)->apply($query, 'occurred_at');

        return array_map(
            'strval',
            $query->orderBy('occurred_at')->pluck('occurred_at')->all(),
        );
    }

    /** @test */
    public function a_record_exactly_at_the_start_of_the_window_is_included(): void
    {
        $this->assertTrue($this->withinWindow('2026-10-01 00:00:00'));
    }

    /** @test */
    public function a_record_during_the_day_is_included(): void
    {
        $this->assertTrue($this->withinWindow('2026-10-01 14:30:00'));
    }

    /**
     * The last second of the day is the boundary the old `<= 'Y-m-d'` predicate
     * lost, so it is asserted on its own rather than only as part of a range.
     */
    /** @test */
    public function a_record_at_the_last_second_of_the_window_day_is_included(): void
    {
        $this->assertTrue($this->withinWindow('2026-10-01 23:59:59'));
    }

    /** @test */
    public function a_record_immediately_after_the_window_is_excluded(): void
    {
        $this->assertFalse($this->withinWindow('2026-10-02 00:00:00'));
    }

    /** @test */
    public function a_record_immediately_before_the_window_is_excluded(): void
    {
        $this->assertFalse($this->withinWindow('2026-09-30 23:59:59'));
    }

    /**
     * A multi-day window is exactly the union of the days it names.
     *
     * The exclusive upper bound is asserted directly as well as behaviourally: it is
     * the single value every report's `<` clause is built from, so changing it should
     * have to change this test. It is a bare `Y-m-d`, which is what makes the rule
     * hold whether the column stores `'2026-10-02'` or `'2026-10-02 00:00:00'`.
     *
     * @test
     */
    public function a_multi_day_window_covers_every_day_it_names_and_no_others(): void
    {
        $range = ReportDateRange::fromFilters(['date_from' => '2026-09-30', 'date_to' => '2026-10-02']);

        $this->assertSame('2026-10-03', $range->exclusiveUpperBound());

        $expected = [
            '2026-09-30 00:00:00',
            '2026-09-30 23:59:59',
            '2026-10-01 12:00:00',
            '2026-10-02 23:59:59',
        ];

        foreach ([...$expected, '2026-10-03 00:00:00'] as $occurredAt) {
            DB::table('report_boundary_probe')->insert(['occurred_at' => $occurredAt]);
        }

        $this->assertSame($expected, $this->selected(['date_from' => '2026-09-30', 'date_to' => '2026-10-02']));
    }

    /**
     * A day stored without a time must fall inside its own window.
     *
     * MySQL writes a `DATE` column as a bare `'2026-10-01'`; SQLite writes
     * `'2026-10-01 00:00:00'` through Eloquent's cast. A lower bound expanded to
     * `'2026-10-01 00:00:00'` would exclude the first form entirely — losing the
     * whole first day of every report — so the bare-day bounds are what make the
     * policy portable rather than driver-specific.
     *
     * @test
     */
    public function a_bare_date_stored_value_is_inside_its_own_day(): void
    {
        DB::table('report_boundary_probe')->insert(['occurred_at' => '2026-10-01']);

        $this->assertContains(
            '2026-10-01',
            $this->selected(['date_from' => self::DAY, 'date_to' => self::DAY]),
        );
    }

    /**
     * An absent bound leaves that side of the window open — the unfiltered report
     * has always meant "everything", not "nothing".
     *
     * @test
     */
    public function an_absent_bound_leaves_that_side_of_the_window_open(): void
    {
        $range = ReportDateRange::fromFilters(['date_from' => '2026-10-01', 'date_to' => null]);

        $this->assertSame('2026-10-01', $range->from());
        $this->assertNull($range->to());

        DB::table('report_boundary_probe')->insert(['occurred_at' => '2099-01-01 08:00:00']);

        $this->assertContains(
            '2099-01-01 08:00:00',
            $this->selected(['date_from' => '2026-10-01', 'date_to' => null]),
            'With no upper bound, a row decades later is still in scope.',
        );
    }

    /**
     * Only an exact `Y-m-d` is ever used as a bound.
     *
     * The request layer already rejects anything else with a 422, so this is the
     * second line of defence: a value that is not a plain calendar day can never
     * reach a comparison, whatever wrote it.
     */
    /** @test */
    public function a_bound_that_is_not_a_calendar_day_is_ignored_rather_than_compared(): void
    {
        $range = ReportDateRange::fromFilters([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-01 OR 1=1',
        ]);

        $this->assertSame('2026-10-01', $range->from());
        $this->assertNull($range->to());
    }

    /**
     * The same rule end to end: a one-day sales window must contain every sale
     * dated that day and nothing from the day before.
     */
    /** @test */
    public function a_one_day_sales_window_includes_every_sale_dated_that_day(): void
    {
        $business = Business::factory()->create();
        $this->actingAsBusinessUser($business);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'cost_price' => '5.00',
            'selling_price' => '10.00',
        ]);

        StockMovement::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '20',
        ]);

        $this->postJson('/api/v1/sales', [
            'sale_date' => self::DAY,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(201);

        $this->postJson('/api/v1/sales', [
            'sale_date' => '2026-09-30',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(201);

        $this->getJson('/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY)
            ->assertStatus(200)
            ->assertJsonPath('data.period.from', self::DAY)
            ->assertJsonPath('data.period.to', self::DAY)
            ->assertJsonPath('data.metrics.sale_count', 1)
            ->assertJsonPath('data.metrics.total_sales', '10.00');
    }
}
