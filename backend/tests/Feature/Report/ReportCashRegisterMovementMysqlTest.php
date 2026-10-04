<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 9 — the **same report semantics executed on the project's real driver**.
 *
 * The SQLite suite proves the logic; this class exists because SQLite and MySQL
 * disagree in exactly the places this report's SQL depends on, and it did so in
 * Stage 7:
 *
 *  - **Aggregate `CASE WHEN … type IN (…)` over a joined, filtered set.** The
 *    placeholders bind positionally, so a count mismatch does not error on one
 *    driver and silently binds the wrong type on the other.
 *  - **`GROUP BY` a qualified column and `ORDER BY` a select alias**, both under
 *    MySQL's default `ONLY_FULL_GROUP_BY`.
 *  - **`DATETIME` comparison** for the half-open `[from, to + 1 day)` window.
 *    SQLite stores a datetime as a *string*, so a boundary rule can look right
 *    there and be wrong here.
 *  - **String `type` columns, not enums** — MySQL's collation and padding are a
 *    class of bug SQLite cannot reproduce at all.
 *  - **A tenant guard in the `ON` clause of a LEFT join**, which is only
 *    meaningful when the driver honours join predicates as written.
 *
 * It asserts the reported figures against directly written rows rather than
 * re-running the whole feature suite, so the MySQL check stays fast and its
 * failures point at the driver rather than at a fixture.
 *
 * @see ReportCashRegisterMovementTest for the full contract
 */
class ReportCashRegisterMovementMysqlTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private const DAY = '2026-01-15';

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite is meaningless on SQLite: it exists precisely for the driver
        // the application actually runs on, so it refuses to pretend otherwise
        // rather than silently re-proving the same assertions.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Stage 9 MySQL verification requires a mysql connection.');
        }

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

    /**
     * @test
     */
    public function the_case_when_in_aggregate_sums_correctly_on_mysql(): void
    {
        $register = $this->register();

        // One of every type, with amounts chosen so a mis-bound placeholder lands on
        // a visibly wrong total rather than a coincidentally right one.
        $this->movement(CashRegisterTransaction::TYPE_SALE, '100.00', $register, '11:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '200.00', $register, '11:01:00');
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '300.00', $register, '11:02:00');
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '400.00', $register, '11:03:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '500.00', $register, '11:04:00');
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '900.00', $register, '11:05:00');
        $this->movement(CashRegisterTransaction::TYPE_CLOSING, '950.00', $register, '11:06:00');

        // sale + cash_in = 300 in; expense + refund + cash_out = 1200 out.
        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '300.00')
            ->assertJsonPath('data.metrics.total_cash_out', '1200.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '-900.00')
            ->assertJsonPath('data.metrics.transaction_count', 7)
            ->assertJsonPath('data.metrics.non_movement_count', 2);
    }

    /**
     * The `IN (…)` placeholder lists are built per constant, so the three-valued
     * outflow list binds three values on MySQL exactly as it does on SQLite.
     *
     * @test
     */
    public function the_three_valued_outflow_list_binds_correctly(): void
    {
        $register = $this->register();

        // If `refund` were missing from the bound list, cash out would read 800
        // instead of 1200 — a wrong total rather than an error, which is exactly the
        // failure mode that has to be caught on the real driver.
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '400.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '500.00', $register);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '1200.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '-1200.00');
    }

    /**
     * `GROUP BY` + `ORDER BY` on a select alias works under `ONLY_FULL_GROUP_BY`.
     *
     * @test
     */
    public function the_group_by_and_order_by_alias_survive_only_full_group_by(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '100.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '50.00', $register);

        $this->assertSame(
            [
                CashRegisterTransaction::TYPE_CASH_IN,
                CashRegisterTransaction::TYPE_SALE,
                CashRegisterTransaction::TYPE_REFUND,
            ],
            array_column($this->report()->json('data.metrics.by_type'), 'type'),
        );
    }

    /**
     * The half-open window behaves on a real `DATETIME` column.
     *
     * @test
     */
    public function the_date_window_works_on_a_real_datetime_column(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '1.00', $register, '2026-01-14 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '2.00', $register, '2026-01-15 00:00:00');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '4.00', $register, '2026-01-15 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '8.00', $register, '2026-01-16 00:00:00');

        $this->getJson('/api/v1/reports/cash-register-movements?date_from='.self::DAY.'&date_to='.self::DAY)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '6.00')
            ->assertJsonPath('data.metrics.transaction_count', 2);
    }

    /**
     * The join-side tenant guard drops a cross-tenant register reference.
     *
     * @test
     */
    public function the_cross_tenant_join_guard_holds_on_mysql(): void
    {
        $theirs = Business::factory()->create();
        $theirRegister = CashRegister::factory()->create([
            'business_id' => $theirs->id,
            'status' => CashRegister::STATUS_OPEN,
        ]);

        // A row of *this* business naming *their* register: legal under the plain
        // foreign key, and the reason the join carries its own guard.
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '5000.00', $theirRegister, businessId: $this->business->id);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '100.00', $this->register());

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '100.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    /**
     * The report's figures equal the raw ledger rows on the real driver.
     *
     * The strongest end-to-end check: aggregate in SQL, compare against a plain PHP
     * sum of the very rows involved.
     *
     * @test
     */
    public function the_figures_match_a_direct_sum_of_the_stored_rows(): void
    {
        $register = $this->register();

        $rows = [
            [CashRegisterTransaction::TYPE_SALE, '150.00'],
            [CashRegisterTransaction::TYPE_CASH_IN, '25.50'],
            [CashRegisterTransaction::TYPE_EXPENSE, '75.25'],
            [CashRegisterTransaction::TYPE_REFUND, '10.00'],
            [CashRegisterTransaction::TYPE_CASH_OUT, '5.25'],
            [CashRegisterTransaction::TYPE_OPENING, '500.00'],
            [CashRegisterTransaction::TYPE_CLOSING, '585.00'],
        ];
        foreach ($rows as $index => [$type, $amount]) {
            $this->movement($type, $amount, $register, '12:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).':00');
        }

        $expectedIn = '0.00';
        $expectedOut = '0.00';
        foreach ($rows as [$type, $amount]) {
            if (in_array($type, CashRegisterTransaction::INFLOW_TYPES, true)) {
                $expectedIn = bcadd($expectedIn, $amount, 2);
            } elseif (in_array($type, CashRegisterTransaction::OUTFLOW_TYPES, true)) {
                $expectedOut = bcadd($expectedOut, $amount, 2);
            }
        }

        $metrics = $this->report()->json('data.metrics');

        $this->assertSame($expectedIn, $metrics['total_cash_in']);
        $this->assertSame($expectedOut, $metrics['total_cash_out']);
        $this->assertSame(bcsub($expectedIn, $expectedOut, 2), $metrics['net_cash_movement']);
        $this->assertSame(count($rows), $metrics['transaction_count']);
    }

    /**
     * The query cost is the same on MySQL as it is on SQLite.
     *
     * @test
     */
    public function the_query_count_is_constant_on_mysql(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, createdBy: $this->user->getKey());

        $this->report()->assertStatus(200);

        $small = $this->countQueries(fn () => $this->report()->assertStatus(200));

        for ($n = 0; $n < 50; $n++) {
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '1.00', $register, createdBy: $this->user->getKey());
        }

        $large = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $this->assertSame($small, $large, 'The query count must not grow with the movement count on MySQL.');
    }

    // ------------------------------------------------------------------
    // Fixtures / helpers
    // ------------------------------------------------------------------

    private function register(string $openingBalance = '0.00'): CashRegister
    {
        return CashRegister::factory()->create([
            'business_id' => $this->business->id,
            'opening_balance' => $openingBalance,
            'opened_at' => Carbon::parse(self::DAY)->setTime(8, 0),
            'status' => CashRegister::STATUS_OPEN,
        ]);
    }

    /**
     * One movement. `$at` is either a bare time on {@see self::DAY} or a full stamp.
     */
    private function movement(
        string $type,
        string $amount,
        ?CashRegister $register = null,
        string $at = '12:00:00',
        ?int $businessId = null,
        ?int $createdBy = null,
    ): CashRegisterTransaction {
        $register ??= $this->register();

        $timestamp = str_contains($at, '-') ? $at : self::DAY.' '.$at;

        $movement = CashRegisterTransaction::create([
            'business_id' => $businessId ?? $this->business->id,
            'cash_register_id' => $register->getKey(),
            'type' => $type,
            'amount' => $amount,
            'created_by' => $createdBy,
        ]);

        $movement->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();

        return $movement;
    }

    private function report(): TestResponse
    {
        return $this->getJson('/api/v1/reports/cash-register-movements');
    }

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
