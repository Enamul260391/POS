<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Services\Report\ReportService;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 9 — **cash register movement**, `GET /reports/cash-register-movements`.
 *
 * This is the first report whose source of truth is a *ledger of cash movements*
 * rather than a business document table, and almost every property worth
 * asserting here follows from that one fact:
 *
 *  - **The report counts what hit the drawer.** A card / bank / cheque sale posts
 *    no `cash_register_transactions` row, so it is absent — and the tests prove it
 *    by running the real flows with a non-cash method and asserting the totals do
 *    not move. Nothing is re-derived from `payments`, `expenses` or
 *    `sale_returns`.
 *  - **Direction comes from the type, never from the sign.** `amount` is an
 *    unsigned magnitude, so a `cash_out` of 500 is stored `500.00`. A report that
 *    classified by sign would read every outflow as an inflow and come out
 *    arithmetically *plausible* — the net figure would simply be wrong, with
 *    nothing else to catch it. Every direction assertion below is made against a
 *    positive-amount outflow for exactly that reason.
 *  - **`opening` / `closing` are markers, not movements.** They are real rows, so
 *    they are counted and listed, but they are in neither `INFLOW_TYPES` nor
 *    `OUTFLOW_TYPES` and can never enter a cash total. A report that treated an
 *    opening float as cash-in would disagree with `CashRegisterService::balance()`,
 *    which adds the float separately.
 *  - **The window really restricts the population.** This is a *period* report, so
 *    it is the opposite of Stage 7/8: the date filter changes the figures rather
 *    than travelling beside them, and the boundary tests walk either side of both
 *    ends of a single day.
 *  - **Tenancy is guarded on both sides of the join.** `cash_register_id` carries
 *    no tenant of its own, so a local row naming a foreign register is possible
 *    and must be dropped — from the summary and the breakdown as well as the
 *    listing, which is why the hostile-row tests assert on the *totals* and not
 *    just on the row list.
 *  - **The cost is three queries.** Asserted against the query log as the movement
 *    count grows, so an N+1 introduced by naming a register or an operator would
 *    fail here rather than in production.
 *
 * @see ReportService::cashRegisterMovements()
 */
class ReportCashRegisterMovementTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day most boundary tests are written around. */
    private const DAY = '2026-01-15';

    private Business $business;

    /** The authenticated user, used as the movement operator. */
    private User $user;

    /** A second business, created on first use by the isolation fixtures. */
    private ?Business $otherBusiness = null;

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
     * A cash register owned by this business.
     *
     * Created through the factory rather than `POST /cash-registers/open` so a
     * movement can be attached to a *closed* register without an `opening` /
     * `closing` pair being forced into the period — the marker tests need a drawer
     * whose movements are the only rows in the window.
     */
    private function register(string $openingBalance = '0.00', ?int $businessId = null): CashRegister
    {
        return CashRegister::factory()->create([
            'business_id' => $businessId ?? $this->business->id,
            'opening_balance' => $openingBalance,
            'opened_at' => Carbon::parse(self::DAY)->setTime(8, 0),
            'status' => CashRegister::STATUS_OPEN,
        ]);
    }

    /**
     * One register row, dated explicitly.
     *
     * Written directly rather than through a document flow, because several of
     * these tests assert on a *single* type in isolation and running the sale or
     * expense flow would also write stock movements and ledger entries that make
     * the fixture describe more than the row under test. The tests that care about
     * the real flow call the real flow.
     *
     * @param  string  $at  `Y-m-d H:i:s`, the value stored in `created_at`.
     */
    private function movement(
        string $type,
        string $amount,
        ?CashRegister $register = null,
        string $at = self::DAY.' 12:00:00',
        ?int $businessId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): CashRegisterTransaction {
        $register ??= $this->register();

        $movement = CashRegisterTransaction::create([
            'business_id' => $businessId ?? $this->business->id,
            'cash_register_id' => $register->getKey(),
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => null,
            'created_by' => $createdBy,
        ]);

        // `created_at` is written explicitly because it is the report's movement
        // timestamp: `Carbon::parse($at)` rather than `now()` is what lets a
        // boundary test place a row either side of midnight.
        $movement->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $movement;
    }

    /** The id of a second business, created once per test. */
    private function otherBusinessId(): int
    {
        $this->otherBusiness ??= Business::factory()->create();

        return $this->otherBusiness->id;
    }

    // ------------------------------------------------------------------
    // Request + extraction helpers
    // ------------------------------------------------------------------

    /**
     * `GET /reports/cash-register-movements`, optionally with a window or filter.
     *
     * @param  array<string, string|int>  $query
     */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson(
            '/api/v1/reports/cash-register-movements'.($query === [] ? '' : '?'.http_build_query($query))
        );
    }

    /** `metrics.movements` exactly as the payload carries it. */
    private function movements(TestResponse $response): array
    {
        return $response->json('data.metrics.movements') ?? [];
    }

    /** `metrics.by_type` exactly as the payload carries it. */
    private function byType(TestResponse $response): array
    {
        return $response->json('data.metrics.by_type') ?? [];
    }

    /** One `by_type` row, found by its stored type. */
    private function breakdownFor(TestResponse $response, string $type): array
    {
        foreach ($this->byType($response) as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }

        return [];
    }

    /** The types present in the listing, in report order. */
    private function typesOf(TestResponse $response): array
    {
        return array_column($this->movements($response), 'type');
    }

    /**
     * A register opened through the real API, so its `opening` marker exists.
     *
     * The real endpoint is used rather than the factory wherever a test is about
     * what the system actually wrote: `open()` is what writes the `opening` row and
     * `setUsernId`-style side effects, so a fixture that skipped it would describe
     * a drawer state the application never produces.
     */
    private function openRegister(string $openingBalance = '0.00'): CashRegister
    {
        $response = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => $openingBalance]);
        $response->assertStatus(201);

        return CashRegister::query()->findOrFail($response->json('data.id'));
    }

    /** A product of this business with stock on hand. */
    private function product(string $sellingPrice = '100.00'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'selling_price' => $sellingPrice,
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->getKey(),
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '100',
            'created_by' => $this->user->getKey(),
        ]);

        return $product;
    }

    /**
     * One sale line, in the shape `POST /sales` expects.
     *
     * @return array<string, mixed>
     */
    private function saleItem(Product $product, string|int $quantity = '1', ?string $unitPrice = null): array
    {
        return array_filter([
            'product_id' => $product->getKey(),
            'quantity' => (string) $quantity,
            'unit_price' => $unitPrice ?? $product->selling_price,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Record a fully cash-paid sale through the API.
     *
     * Over HTTP so the fixture carries the real `payments` row, the stock
     * movement and — the point of these tests — the automatic `sale` register row
     * that `PaymentService::recordForSale()` writes for a cash method.
     */
    private function cashSale(CashRegister $register, string $amount, ?string $method = null): array
    {
        $method ??= Payment::PAYMENT_METHOD_CASH;

        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [$this->saleItem($this->product(), '1', $amount)],
            'payments' => [['payment_method' => $method, 'amount' => $amount]],
        ]);

        $response->assertStatus(201);

        return $response->json('data');
    }

    /**
     * Record a sale through the API.
     *
     * Over HTTP so the fixture carries the real `payments` row, the stock movement
     * and — the point of these tests — the automatic `sale` register row that
     * `SaleService::create()` / `PaymentService::recordForSale()` write for a cash
     * method. `POST /sales` takes a single `paid_amount` + `payment_method` pair
     * rather than a payments array, so a "mixed" sale is built as a partly-paid
     * invoice settled later through `POST /sales/{id}/payments` — which is how the
     * real system reaches that state.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed> the created sale
     */
    private function createSale(array $extra = [], string $grandTotal = '100.00'): array
    {
        $response = $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => now()->toDateString(),
            // One unit priced to the total the test wants to collect, so the
            // fixture never trips the "paid exceeds grand total" business rule.
            'items' => [$this->saleItem($this->product($grandTotal), '1', $grandTotal)],
        ], $extra));

        $response->assertStatus(201);

        return $response->json('data');
    }

    /**
     * Sell, then return the goods refunding `refundAmount` by `$method`.
     *
     * The real return flow, so the fixture carries whatever drawer row (or none)
     * `SaleReturnService::postCashRefund()` actually wrote for that method — which
     * is the entire point of the refund assertions.
     *
     * `$saleMethod` matters: `refundPlan()` caps every requested allocation at
     * what `netCollectedThrough()` says was actually collected by that method, so
     * asking for a bank refund against a cash-paid sale is not a "non-cash
     * refund" — the plan falls back to the method the money actually came in
     * through, and cash leaves the drawer legitimately. To get a genuinely
     * non-cash refund the sale has to have been paid that way.
     *
     * @return array<string, mixed> the created sale return
     */
    private function refundAgainstCashSale(
        string $refundAmount,
        string $method,
        string $saleMethod = Payment::PAYMENT_METHOD_CASH,
    ): array {
        $sale = $this->createSale([
            'paid_amount' => '300.00',
            'payment_method' => $saleMethod,
        ], '300.00');

        $response = $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'reason' => 'Customer return',
            'items' => [[
                'sale_item_id' => $sale['items'][0]['id'],
                'quantity' => '1',
            ]],
            'refunds' => [
                ['payment_method' => $method, 'amount' => $refundAmount],
            ],
        ]);

        $response->assertStatus(201);

        return $response->json('data');
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
    // Endpoint existence and the permission contract
    // ------------------------------------------------------------------

    /**
     * The endpoint exists, is named, and answers the standard success envelope.
     *
     * @test
     */
    public function the_endpoint_exists_and_answers_the_standard_envelope(): void
    {
        $this->assertTrue(
            Route::has('api.v1.reports.cash-register-movements'),
            'The route is registered under its own name.'
        );

        $this->report()->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['from', 'to'],
                    'metrics' => [
                        'total_cash_in',
                        'total_cash_out',
                        'net_cash_movement',
                        'transaction_count',
                        'by_type',
                        'movements',
                    ],
                ],
            ]);
    }

    /**
     * An unauthenticated caller is refused before any data is touched.
     *
     * @test
     */
    public function an_unauthenticated_caller_is_unauthorised(): void
    {
        $this->postJson('/api/v1/auth/logout');
        auth()->forgetGuards();
        $this->withHeader('Authorization', '');

        $this->report()->assertStatus(401);
    }

    /**
     * An authenticated user without `reports.view` gets 403 — not 200, not 404.
     *
     * @test
     */
    public function a_user_without_reports_view_is_forbidden(): void
    {
        $this->userWithoutPermissions();

        $this->report()->assertStatus(403);
    }

    /**
     * An unrelated permission alone is *not* enough.
     *
     * `reports.view` is its own capability; reading the sales or expenses report
     * does not imply it, and neither does managing the drawer.
     *
     * @test
     */
    public function an_unrelated_permission_alone_does_not_authorise_the_report(): void
    {
        foreach ([PermissionService::SALES_VIEW, PermissionService::EXPENSES_VIEW, PermissionService::CASH_REGISTERS_VIEW] as $capability) {
            $this->userWithPermissions([$capability], $this->business->id);

            $this->assertSame(
                403,
                $this->report()->getStatusCode(),
                "{$capability} alone must not open the cash register movement report."
            );
        }
    }

    /**
     * `reports.view` alone is sufficient, and it is the whole permission.
     *
     * @test
     */
    public function reports_view_alone_authorises_the_report(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '75.00');
        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $this->report()->assertStatus(200)->assertJsonPath('data.metrics.total_cash_in', '75.00');
    }

    // ------------------------------------------------------------------
    // Summary metrics
    // ------------------------------------------------------------------

    /**
     * Cash in, cash out and net are three separate figures with a stated identity.
     *
     * @test
     */
    public function the_summary_reports_cash_in_cash_out_and_their_difference(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '100.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '40.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '60.00', $register);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '400.00')
            ->assertJsonPath('data.metrics.total_cash_out', '100.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '300.00');
    }

    /**
     * `net_cash_movement` is exactly `total_cash_in - total_cash_out`, by name.
     *
     * @test
     */
    public function net_cash_movement_is_cash_in_less_cash_out(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '1250.75', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '49.25', $register);
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '99.99', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '0.01', $register);

        $metrics = $this->report()->json('data.metrics');

        $this->assertSame(
            bcsub($metrics['total_cash_in'], $metrics['total_cash_out'], 2),
            $metrics['net_cash_movement'],
        );
    }

    /**
     * `transaction_count` counts register rows, and says how many moved cash.
     *
     * The markers are real rows and are counted; they are also broken out
     * separately so the count can never be read as a movement count it is not.
     *
     * @test
     */
    public function transaction_count_counts_rows_and_separates_the_markers(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '10.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '5.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '100.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CLOSING, '105.00', $register);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.transaction_count', 4)
            ->assertJsonPath('data.metrics.non_movement_count', 2)
            ->assertJsonPath('data.metrics.cash_movement_count', 2);
    }

    /**
     * An empty dataset is all zeroes and no rows — never an error, never null.
     *
     * @test
     */
    public function an_empty_dataset_reports_zeroes_and_no_rows(): void
    {
        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.total_cash_out', '0.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 0)
            ->assertJsonPath('data.metrics.cash_movement_count', 0)
            ->assertJsonPath('data.metrics.non_movement_count', 0)
            ->assertJsonPath('data.metrics.by_type', [])
            ->assertJsonPath('data.metrics.movements', []);
    }

    /**
     * The aggregates are computed in SQL, never summed in PHP.
     *
     * @test
     */
    public function the_summary_is_a_sql_aggregation(): void
    {
        $register = $this->register();
        foreach (range(1, 12) as $index) {
            $this->movement(CashRegisterTransaction::TYPE_SALE, (string) $index, $register);
        }

        $queries = [];
        DB::enableQueryLog();
        $this->report()->assertStatus(200);
        foreach (DB::getQueryLog() as $entry) {
            if (str_starts_with(strtoupper($entry['query']), 'SELECT')) {
                $queries[] = $entry['query'];
            }
        }
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        $this->assertTrue(
            (bool) preg_grep('/SUM\(CASE WHEN/', $queries),
            'The cash totals must come from a CASE/SUM aggregate in SQL.'
        );
    }

    // ------------------------------------------------------------------
    // Transaction types: direction, amount and count per canonical type
    // ------------------------------------------------------------------

    /**
     * Every canonical type lands on the right side with the right figure.
     *
     * Driven off the constants rather than a hand-written table, so the test
     * itself cannot drift from the schema — and so a type added to the model would
     * fail here rather than go silently unreported.
     *
     * @test
     */
    public function every_canonical_type_is_classified_by_its_own_direction(): void
    {
        $register = $this->register();
        foreach (CashRegisterTransaction::TYPES as $index => $type) {
            // A distinct amount per type, so a misclassification shows up as a
            // wrong number and not merely as a swapped direction.
            $this->movement($type, (string) (100 + $index), $register);
        }

        $response = $this->report()->assertStatus(200);

        foreach (CashRegisterTransaction::TYPES as $index => $type) {
            // A distinct amount per type, so a misclassification shows up as a
            // wrong number and not merely as a swapped direction. Compared through
            // `Money::format` because the report publishes every figure at the
            // storage scale — an integer fixture is `100.00` on the wire.
            $amount = Money::format((string) (100 + $index));
            $row = $this->breakdownFor($response, $type);

            $this->assertNotSame([], $row, "{$type} must appear in the breakdown.");
            $this->assertSame($amount, $row['amount'], "{$type} must report its own amount.");
            $this->assertSame(1, $row['transaction_count'], "{$type} must be counted once.");

            $expected = match (true) {
                in_array($type, CashRegisterTransaction::INFLOW_TYPES, true) => 'in',
                in_array($type, CashRegisterTransaction::OUTFLOW_TYPES, true) => 'out',
                default => 'non_movement',
            };
            $this->assertSame($expected, $row['direction'], "{$type} must be {$expected}.");
        }
    }

    /**
     * Amounts are unsigned, so an outflow must be classified as an outflow anyway.
     *
     * The whole report rests on this. `amount` is stored as a positive magnitude
     * — a `cash_out` of 500 is `500.00`, never `-500.00` — so a report reading
     * direction from the sign would call every outflow an inflow. That error is
     * *silent*: the totals stay positive and plausible and only the net figure is
     * wrong. Asserting on positive-amount outflows is the only way to catch it.
     *
     * @test
     */
    public function an_outflow_is_stored_positive_and_is_still_an_outflow(): void
    {
        $register = $this->register();
        $cashOut = $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '500.00', $register);
        $refund = $this->movement(CashRegisterTransaction::TYPE_REFUND, '250.00', $register);

        // The premise, stated on the schema rather than assumed.
        $this->assertSame('500.00', $cashOut->amount);
        $this->assertSame('250.00', $refund->amount);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.total_cash_out', '750.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '-750.00');

        $this->assertSame('out', $this->breakdownFor($response, CashRegisterTransaction::TYPE_CASH_OUT)['direction']);
        $this->assertSame('out', $this->breakdownFor($response, CashRegisterTransaction::TYPE_REFUND)['direction']);
    }

    /**
     * The two manual types are manual; the three automatic ones are automatic.
     *
     * `origin` restates the schema's own boundary — `MANUAL_TYPES` is what
     * `recordManual()` writes and `AUTOMATIC_TYPES` is what
     * `postAutomaticTransaction()` whitelists — so this asserts the report and the
     * write paths agree rather than asserting a new classification.
     *
     * @test
     */
    public function manual_and_automatic_origins_match_the_writes_that_produced_them(): void
    {
        $register = $this->register();
        foreach (CashRegisterTransaction::TYPES as $type) {
            $this->movement($type, '10.00', $register);
        }

        $response = $this->report()->assertStatus(200);

        foreach (CashRegisterTransaction::MANUAL_TYPES as $type) {
            $this->assertSame('manual', $this->breakdownFor($response, $type)['origin'], "{$type} is manual.");
        }

        foreach (CashRegisterTransaction::AUTOMATIC_TYPES as $type) {
            $this->assertSame('automatic', $this->breakdownFor($response, $type)['origin'], "{$type} is automatic.");
        }

        foreach (CashRegisterTransaction::NON_MOVEMENT_TYPES as $type) {
            $this->assertSame('non_movement', $this->breakdownFor($response, $type)['origin']);
        }
    }

    /**
     * `by_type` totals reconcile with the summary on both sides.
     *
     * Summing the breakdown by direction has to reproduce the header exactly — the
     * identity that makes the two blocks checkable rather than merely adjacent.
     *
     * @test
     */
    public function the_breakdown_sums_back_to_the_summary_totals(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '900.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '100.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '120.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '80.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '20.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '500.00', $register);

        $metrics = $this->report()->json('data.metrics');

        $in = '0.00';
        $out = '0.00';
        foreach ($metrics['by_type'] as $row) {
            if ($row['direction'] === 'in') {
                $in = bcadd($in, $row['amount'], 2);
            } elseif ($row['direction'] === 'out') {
                $out = bcadd($out, $row['amount'], 2);
            }
        }

        $this->assertSame($metrics['total_cash_in'], $in);
        $this->assertSame($metrics['total_cash_out'], $out);
    }

    /**
     * The opening and closing rows are counted and listed, never summed.
     *
     * A report that let an opening float into cash-in would disagree with
     * `CashRegisterService::balance()`, which adds the float separately.
     *
     * @test
     */
    public function the_open_and_close_markers_are_listed_but_never_summed(): void
    {
        $register = $this->register('1000.00');
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '1000.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '50.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CLOSING, '1050.00', $register);

        $response = $this->report()->assertStatus(200)
            // Only the real movement counts; neither marker is cash in or out.
            ->assertJsonPath('data.metrics.total_cash_in', '50.00')
            ->assertJsonPath('data.metrics.total_cash_out', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 3)
            ->assertJsonPath('data.metrics.non_movement_count', 2);

        $this->assertContains(CashRegisterTransaction::TYPE_OPENING, $this->typesOf($response));
        $this->assertContains(CashRegisterTransaction::TYPE_CLOSING, $this->typesOf($response));
        $this->assertSame(
            'non_movement',
            $this->breakdownFor($response, CashRegisterTransaction::TYPE_OPENING)['direction']
        );
    }

    /**
     * The breakdown is ordered deterministically, not by whatever the driver did.
     *
     * Two identical requests must agree exactly, which an implicit row order
     * leaves to chance.
     *
     * @test
     */
    public function the_breakdown_order_is_deterministic_across_requests(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '100.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '300.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_REFUND, '50.00', $register);

        $first = array_column($this->byType($this->report()), 'type');
        $second = array_column($this->byType($this->report()), 'type');

        $this->assertSame($first, $second);

        // Largest first; the equal 300s are separated by the type name so the
        // order is *total* rather than merely stable within one request.
        $this->assertSame(
            [
                CashRegisterTransaction::TYPE_CASH_IN,
                CashRegisterTransaction::TYPE_EXPENSE,
                CashRegisterTransaction::TYPE_SALE,
                CashRegisterTransaction::TYPE_REFUND,
            ],
            $first,
        );
    }

    // ------------------------------------------------------------------
    // Cash semantics: only real drawer movement counts
    // ------------------------------------------------------------------

    /**
     * A real cash sale posts a drawer movement, and it is counted as cash in.
     *
     * @test
     */
    public function a_cash_sale_is_counted_as_cash_in(): void
    {
        $register = $this->openRegister();

        $this->createSale([
            'paid_amount' => '250.00',
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ], '250.00');

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '250.00')
            // One `opening` marker plus one real `sale` movement.
            ->assertJsonPath('data.metrics.transaction_count', 2)
            ->assertJsonPath('data.metrics.cash_movement_count', 1)
            ->assertJsonPath('data.metrics.non_movement_count', 1);

        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->getKey(),
            'type' => CashRegisterTransaction::TYPE_SALE,
            'amount' => '250.00',
        ]);
    }

    /**
     * A card / bank / cheque sale posts **no** drawer row, so it is not cash.
     *
     * The single most important exclusion in this report: the invoice exists, the
     * `Payment` row exists, and the money never touched the drawer. Re-deriving
     * cash from `payments` would report it as an inflow and the whole report would
     * be a cash *receipts* report wearing the wrong name.
     *
     * @test
     */
    public function a_non_cash_sale_is_never_invented_as_cash(): void
    {
        $this->openRegister();

        foreach ([Payment::PAYMENT_METHOD_CARD, Payment::PAYMENT_METHOD_BANK, Payment::PAYMENT_METHOD_CHEQUE] as $method) {
            $this->createSale([
                'paid_amount' => '300.00',
                'payment_method' => $method,
            ], '300.00');
        }

        // The premise, on the schema: three non-cash sales, and only the `opening`
        // marker the drawer was opened with.
        $this->assertDatabaseCount('cash_register_transactions', 1);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.cash_movement_count', 0)
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    /**
     * A mixed payment counts only the cash portion, because only that has a row.
     *
     * @test
     */
    public function a_mixed_payment_counts_only_its_real_cash_portion(): void
    {
        $this->openRegister();

        // Half in cash at the counter, the other half settled later by card.
        $sale = $this->createSale([
            'paid_amount' => '150.00',
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ], '400.00');

        $this->postJson('/api/v1/sales/'.$sale['id'].'/payments', [
            'amount' => '250.00',
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
        ])->assertStatus(201);

        // The whole 400 was collected, but only the 150 that was handed over in
        // banknotes has a drawer row.
        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '150.00')
            // The opening marker plus exactly one real movement.
            ->assertJsonPath('data.metrics.transaction_count', 2)
            ->assertJsonPath('data.metrics.cash_movement_count', 1)
            ->assertJsonPath('data.metrics.non_movement_count', 1);
    }

    /**
     * A credit sale never touches the drawer and is not cash.
     *
     * @test
     */
    public function a_credit_sale_is_not_a_cash_movement(): void
    {
        $register = $this->openRegister();

        $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [$this->saleItem($this->product(), 1, '500.00')],
        ])->assertStatus(201);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);

        $this->assertSame([CashRegisterTransaction::TYPE_OPENING], $this->typesOf($this->report()));
    }

    /**
     * A cash expense is counted as cash out; a bank one is not re-derived.
     *
     * @test
     */
    public function a_cash_expense_is_cash_out_and_a_bank_expense_is_not(): void
    {
        $register = $this->openRegister();

        $this->postJson('/api/v1/expenses', [
            'amount' => '175.00',
            'expense_date' => now()->toDateString(),
        ])->assertStatus(201);

        $this->postJson('/api/v1/expenses', [
            'amount' => '900.00',
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_BANK,
        ])->assertStatus(201);

        // Only the cash expense has a drawer row.
        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '175.00')
            ->assertJsonPath('data.metrics.total_cash_in', '0.00');

        $this->assertDatabaseCount('cash_register_transactions', 2);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->getKey(),
            'type' => CashRegisterTransaction::TYPE_EXPENSE,
            'amount' => '175.00',
        ]);
    }

    /**
     * A cash refund is cash out, because the return flow posted a drawer row.
     *
     * @test
     */
    public function a_cash_refund_is_counted_as_cash_out(): void
    {
        $this->openRegister();

        $this->refundAgainstCashSale('200.00', Payment::PAYMENT_METHOD_CASH);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '200.00')
            // The 300 cash sale is still an inflow; the 200 refund is the only
            // outflow, so the net is the difference rather than a wash.
            ->assertJsonPath('data.metrics.net_cash_movement', '100.00');

        $this->assertDatabaseHas('cash_register_transactions', [
            'type' => CashRegisterTransaction::TYPE_REFUND,
            'amount' => '200.00',
            'reference_type' => SaleReturn::class,
        ]);
    }

    /**
     * A non-cash refund is never invented as cash out.
     *
     * A `SaleReturn` exists in both cases, so any report re-deriving cash from the
     * return would show this one as money leaving the drawer. Only the cash
     * allocation has a register row, and only that is reported.
     *
     * @test
     */
    public function a_non_cash_refund_is_not_invented_as_cash_out(): void
    {
        $this->openRegister();

        // The sale was paid by bank, so the refund is paid back by bank: a real
        // `SaleReturn` and a real refund `Payment`, and no drawer movement at all.
        $this->refundAgainstCashSale('300.00', Payment::PAYMENT_METHOD_BANK, Payment::PAYMENT_METHOD_BANK);

        $this->assertDatabaseHas('payments', [
            'type' => Payment::TYPE_REFUND,
            'payment_method' => Payment::PAYMENT_METHOD_BANK,
        ]);
        $this->assertDatabaseMissing('cash_register_transactions', [
            'type' => CashRegisterTransaction::TYPE_REFUND,
        ]);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '0.00')
            // The bank sale posted no drawer row either, so cash in is zero too.
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.cash_movement_count', 0);
    }

    /**
     * A return is never counted twice — once as a return, once as a drawer row.
     *
     * @test
     */
    public function a_refund_is_counted_once_not_double_counted(): void
    {
        $this->openRegister();

        $this->refundAgainstCashSale('150.00', Payment::PAYMENT_METHOD_CASH);

        $metrics = $this->report()->json('data.metrics');

        $this->assertSame('150.00', $metrics['total_cash_out']);
        $this->assertSame(1, $this->breakdownFor($this->report(), CashRegisterTransaction::TYPE_REFUND)['transaction_count']);
    }

    // ------------------------------------------------------------------
    // Manual cash movements
    // ------------------------------------------------------------------

    /**
     * A manual cash in is counted, and is classified as manual.
     *
     * @test
     */
    public function a_manual_cash_in_is_included_in_cash_in(): void
    {
        $register = $this->openRegister();

        $this->postJson('/api/v1/cash-registers/'.$register->getKey().'/transactions', [
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => '500.00',
            'note' => 'Float top-up',
        ])->assertStatus(201);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '500.00');

        $row = $this->breakdownFor($response, CashRegisterTransaction::TYPE_CASH_IN);
        $this->assertSame('in', $row['direction']);
        $this->assertSame('manual', $row['origin']);
        $this->assertSame('500.00', $row['amount']);
        $this->assertSame(1, $row['transaction_count']);
    }

    /**
     * A manual cash out is counted, and is classified as manual.
     *
     * @test
     */
    public function a_manual_cash_out_is_included_in_cash_out(): void
    {
        $register = $this->openRegister();

        $this->postJson('/api/v1/cash-registers/'.$register->getKey().'/transactions', [
            'type' => CashRegisterTransaction::TYPE_CASH_OUT,
            'amount' => '125.00',
        ])->assertStatus(201);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '125.00')
            ->assertJsonPath('data.metrics.net_cash_movement', '-125.00');

        $row = $this->breakdownFor($response, CashRegisterTransaction::TYPE_CASH_OUT);
        $this->assertSame('out', $row['direction']);
        $this->assertSame('manual', $row['origin']);
    }

    /**
     * Manual and automatic movements land on their own sides of the breakdown.
     *
     * @test
     */
    public function manual_and_automatic_movements_are_broken_down_separately(): void
    {
        $register = $this->openRegister();

        $this->createSale([
            'paid_amount' => '100.00',
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ], '100.00');

        $this->postJson('/api/v1/cash-registers/'.$register->getKey().'/transactions', [
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => '40.00',
        ])->assertStatus(201);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '140.00');

        $this->assertSame('automatic', $this->breakdownFor($response, CashRegisterTransaction::TYPE_SALE)['origin']);
        $this->assertSame('manual', $this->breakdownFor($response, CashRegisterTransaction::TYPE_CASH_IN)['origin']);
    }

    // ------------------------------------------------------------------
    // Date boundaries — the shared ReportDateRange half-open window
    // ------------------------------------------------------------------

    /**
     * One movement at each side of the window, and the range between them.
     *
     * This is what makes the "period report" claim checkable: the window must
     * genuinely *remove* rows rather than travel alongside the figures.
     *
     * @test
     */
    public function the_window_includes_both_ends_and_excludes_either_side(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_SALE, '1.00', $register, '2026-01-14 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '2.00', $register, '2026-01-15 00:00:00');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '4.00', $register, '2026-01-15 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_SALE, '8.00', $register, '2026-01-16 00:00:00');

        // The exact first and last instants of the day are both inside; the second
        // either side is outside. 2 + 4 = 6.
        $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '6.00')
            ->assertJsonPath('data.metrics.transaction_count', 2);
    }

    /**
     * A movement stamped at exactly `date_from` is inside an open-ended window.
     *
     * @test
     */
    public function the_exact_from_boundary_is_included(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, '2026-01-15 00:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '20.00', $register, '2026-01-14 23:59:59');

        $this->report(['date_from' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '10.00');
    }

    /**
     * A movement stamped a second before `date_from` is outside it.
     *
     * @test
     */
    public function a_movement_immediately_before_from_is_excluded(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, self::DAY.' 00:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '99.00', $register, '2026-01-14 23:59:59');

        $this->report(['date_from' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '10.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    /**
     * The whole of `date_to` belongs to the window, not just its midnight.
     *
     * The half-open rule is what makes this hold: `<= '2026-01-15'` would drop the
     * 23:59:59 row outright, which is exactly the bug the shared range class
     * exists to prevent.
     *
     * @test
     */
    public function the_exact_to_boundary_includes_the_whole_last_day(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '5.00', $register, self::DAY.' 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '7.00', $register, '2026-01-16 00:00:00');

        $this->report(['date_to' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '5.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    /**
     * A movement stamped a second after `date_to` is outside it.
     *
     * @test
     */
    public function a_movement_immediately_after_to_is_excluded(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '5.00', $register, self::DAY.' 23:59:59');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '7.00', $register, '2026-01-16 00:00:01');

        $this->report(['date_to' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '5.00');
    }

    /**
     * `date_from` alone leaves the right of the window open.
     *
     * @test
     */
    public function a_from_only_window_is_open_on_the_right(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, '2026-01-15 00:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '30.00', $register, '2026-03-20 09:00:00');

        $this->report(['date_from' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '40.00')
            ->assertJsonPath('data.period.from', self::DAY)
            ->assertJsonPath('data.period.to', null);
    }

    /**
     * `date_to` alone leaves the left of the window open.
     *
     * @test
     */
    public function a_to_only_window_is_open_on_the_left(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '11.00', $register, '2025-12-31 23:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '22.00', $register, '2026-01-15 09:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '44.00', $register, '2026-02-01 09:00:00');

        $this->report(['date_to' => self::DAY])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '33.00')
            ->assertJsonPath('data.period.from', null)
            ->assertJsonPath('data.period.to', self::DAY);
    }

    /**
     * No window at all is the whole history, with both bounds reported null.
     *
     * @test
     */
    public function an_unfiltered_request_covers_every_movement(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '13.00', $register, '2024-01-05 10:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '17.00', $register, '2026-01-15 10:00:00');

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '30.00')
            ->assertJsonPath('data.period.from', null)
            ->assertJsonPath('data.period.to', null);
    }

    /**
     * A range with nothing in it is a valid, empty, successful answer.
     *
     * @test
     */
    public function an_empty_range_reports_zeroes_rather_than_an_error(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '500.00', $register);

        $this->report(['date_from' => '2026-02-01', 'date_to' => '2026-02-28'])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.total_cash_out', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 0)
            ->assertJsonPath('data.metrics.by_type', [])
            ->assertJsonPath('data.metrics.movements', []);
    }

    /**
     * A malformed window is a 422, never a silently ignored filter.
     *
     * @test
     */
    public function a_malformed_window_is_rejected(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '500.00');

        $this->report(['date_from' => '15-01-2026'])->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        // `date_to` before `date_from` is refused on the established rule.
        $this->report(['date_from' => '2026-02-01', 'date_to' => '2026-01-01'])->assertStatus(422)
            ->assertJsonValidationErrors('date_to');
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Naming one of this business's registers restricts every aggregate to it.
     *
     * The filter has to bind the summary and the breakdown as well as the listing,
     * or a total would sit above rows it does not describe.
     *
     * @test
     */
    public function a_register_filter_restricts_the_whole_report(): void
    {
        $mine = $this->register();
        $other = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '100.00', $mine);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '250.00', $other);

        $response = $this->report(['cash_register_id' => $mine->getKey()])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '100.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);

        foreach ($this->movements($response) as $row) {
            $this->assertSame($mine->getKey(), $row['cash_register_id']);
        }
    }

    /**
     * No register filter covers every register this tenant owns.
     *
     * @test
     */
    public function no_register_filter_covers_all_of_this_businesss_registers(): void
    {
        $first = $this->register();
        $second = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '60.00', $first);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '20.00', $second);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '60.00')
            ->assertJsonPath('data.metrics.total_cash_out', '20.00')
            ->assertJsonPath('data.metrics.transaction_count', 2);

        $registers = array_values(array_unique(array_column($this->movements($response), 'cash_register_id')));
        sort($registers);
        $this->assertSame([$first->getKey(), $second->getKey()], $registers);
    }

    /**
     * Another business's register id is a 422, not a silent empty report.
     *
     * @test
     */
    public function a_foreign_register_filter_is_rejected(): void
    {
        $foreign = $this->register('0.00', $this->otherBusinessId());

        $this->report(['cash_register_id' => $foreign->getKey()])->assertStatus(422)
            ->assertJsonValidationErrors('cash_register_id');
    }

    /**
     * A register id that does not exist at all is refused on the same rule.
     *
     * @test
     */
    public function an_unknown_register_filter_is_rejected(): void
    {
        $this->report(['cash_register_id' => 987654])->assertStatus(422)
            ->assertJsonValidationErrors('cash_register_id');
    }

    /**
     * The type filter narrows to one canonical type.
     *
     * @test
     */
    public function a_transaction_type_filter_restricts_the_report(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '80.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '30.00', $register);

        $response = $this->report(['transaction_type' => CashRegisterTransaction::TYPE_CASH_IN])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '80.00')
            ->assertJsonPath('data.metrics.total_cash_out', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);

        $this->assertSame([CashRegisterTransaction::TYPE_CASH_IN], $this->typesOf($response));
    }

    /**
     * Every canonical type is accepted by the filter — including the markers.
     *
     * @test
     */
    public function every_canonical_type_is_an_acceptable_filter_value(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '20.00', $register);

        foreach (CashRegisterTransaction::TYPES as $type) {
            $this->report(['transaction_type' => $type])->assertStatus(200);
        }
    }

    /**
     * An unknown type is refused, never silently matched against nothing.
     *
     * The difference matters: a 422 says "that is not a type", while an empty 200
     * says "this business had no such movements" — which for a mistyped filter is
     * simply a lie.
     *
     * @test
     */
    public function an_unknown_transaction_type_is_rejected(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        // Note the case sensitivity too: the column is a `string`, not a MySQL
        // `ENUM`, so `SALE` is genuinely a different stored value and not an
        // alias for `sale` — refusing it is correct rather than pedantic.
        foreach (['withdrawal', 'SALE', 'refund_policy', 'cash_in; DROP TABLE users'] as $bogus) {
            $this->report(['transaction_type' => $bogus])->assertStatus(422)
                ->assertJsonValidationErrors('transaction_type');
        }
    }

    /**
     * A padded type is normalised by the framework and behaves like the bare type.
     *
     * Laravel's global `TrimStrings` middleware normalises query input before
     * `Rule::in()` and before `$this->input()` ever see it, so `' cash_in'` and
     * `'cash_in '` are the same filter. Asserted rather than rejected, because a
     * report that 422'd on a stray space would be fighting the framework instead of
     * describing the drawer.
     *
     * @test
     */
    public function a_padded_type_is_normalised_rather_than_rejected(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        // `urlencode`d by hand so a real space reaches the server:
        // `http_build_query()` would emit `+cash_in`, which the query parser reads
        // back as a literal `+`.
        $this->getJson('/api/v1/reports/cash-register-movements?transaction_type='.urlencode(' cash_in '))
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '10.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    /**
     * The two filters compose with the window.
     *
     * @test
     */
    public function the_register_and_type_filters_compose_with_the_window(): void
    {
        $mine = $this->register();
        $other = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '11.00', $mine, self::DAY.' 10:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '22.00', $mine, '2026-02-01 10:00:00');
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '33.00', $mine, self::DAY.' 11:00:00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '44.00', $other, self::DAY.' 10:00:00');

        $this->report([
            'cash_register_id' => $mine->getKey(),
            'transaction_type' => CashRegisterTransaction::TYPE_CASH_IN,
            'date_from' => self::DAY,
            'date_to' => self::DAY,
        ])->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '11.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's movements are invisible, whatever the register.
     *
     * @test
     */
    public function another_businesss_movements_are_never_reported(): void
    {
        $theirs = $this->otherBusinessId();
        $theirRegister = $this->register('0.00', $theirs);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '999.00', $theirRegister, businessId: $theirs);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '0.00')
            ->assertJsonPath('data.metrics.transaction_count', 0)
            ->assertJsonPath('data.metrics.movements', []);
    }

    /**
     * A **local** movement naming a **foreign** register is dropped entirely.
     *
     * The hostile row the ledger's own `business_id` cannot catch:
     * `cash_register_transactions.cash_register_id` is a plain foreign key with no
     * composite constraint, so scoping the ledger side alone would report another
     * business's register id and operator against this business's money. Asserted
     * on the *totals* and the breakdown as well as the listing, because all three
     * share one scope — a row surviving only in the listing would still be a leak,
     * and one surviving only in the totals would be worse.
     *
     * @test
     */
    public function a_local_movement_naming_a_foreign_register_is_dropped(): void
    {
        $theirRegister = $this->register('0.00', $this->otherBusinessId());

        // This business's own ledger row, pointing at somebody else's drawer.
        $hostile = $this->movement(
            CashRegisterTransaction::TYPE_CASH_IN,
            '5000.00',
            $theirRegister,
            businessId: $this->business->id,
        );

        $legitimate = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '100.00');

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '100.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);

        $ids = array_column($this->movements($response), 'id');
        $this->assertNotContains($hostile->getKey(), $ids);
        $this->assertContains($legitimate->getKey(), $ids);
        $this->assertSame(['100.00'], array_column($this->byType($response), 'amount'));
    }

    /**
     * A **foreign** movement naming a **local** register is dropped too.
     *
     * The mirror image: the register belongs here, the ledger row does not. The
     * row's own tenant is what must decide, and it excludes this one.
     *
     * @test
     */
    public function a_foreign_movement_naming_a_local_register_is_dropped(): void
    {
        $mine = $this->register();

        $hostile = $this->movement(
            CashRegisterTransaction::TYPE_CASH_OUT,
            '7777.00',
            $mine,
            businessId: $this->otherBusinessId(),
        );

        $this->movement(CashRegisterTransaction::TYPE_CASH_OUT, '30.00', $mine);

        $response = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_out', '30.00')
            ->assertJsonPath('data.metrics.transaction_count', 1);

        $this->assertNotContains($hostile->getKey(), array_column($this->movements($response), 'id'));
    }

    /**
     * Two businesses may hold registers at once; neither tenant sees the other's.
     *
     * `cash_registers` has no `name` column, so the two drawers share no label and
     * can only ever collide on nothing — which is precisely why identity has to come
     * from the id and be guarded on both sides. Each business reads the same
     * endpoint and sees only its own rows.
     *
     * @test
     */
    public function registers_in_two_businesses_stay_separate(): void
    {
        $mine = $this->register();
        $theirRegister = $this->register('0.00', $this->otherBusinessId());
        $theirUser = User::factory()->create(['business_id' => $this->otherBusinessId()]);
        // The second tenant needs `reports.view` too, or the read would be a 403 and
        // the assertion would pass for the wrong reason.
        $this->grantAllCapabilities($theirUser);

        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '400.00', $mine);
        // This one belongs to *their* tenant: the `movement()` helper defaults
        // `business_id` to the test business, so it has to be named explicitly or
        // the second half of the test would read this business's rows again.
        $this->movement(
            CashRegisterTransaction::TYPE_CASH_IN,
            '700.00',
            $theirRegister,
            businessId: $theirUser->business_id,
        );

        $asMine = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '400.00');
        $this->assertSame(
            [$mine->getKey()],
            array_values(array_unique(array_column($this->movements($asMine), 'cash_register_id'))),
        );

        // The other tenant, through the very same endpoint, sees only its own.
        $this->authenticate($theirUser);
        $asTheirs = $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '700.00');
        $this->assertSame(
            [$theirRegister->getKey()],
            array_values(array_unique(array_column($this->movements($asTheirs), 'cash_register_id'))),
        );
    }

    /**
     * A movement naming a foreign operator is reported, but the operator is not.
     *
     * The money is this business's, so dropping the row would understate it; but the
     * person is not, so the row is reported with `created_by` null rather than
     * attributing the movement to a stranger. This is what the guarded LEFT join
     * buys.
     *
     * @test
     */
    public function a_movement_recorded_by_a_foreign_user_is_reported_without_that_user(): void
    {
        $foreignUser = User::factory()->create(['business_id' => $this->otherBusinessId()]);

        $this->movement(
            CashRegisterTransaction::TYPE_CASH_IN,
            '640.00',
            $this->register(),
            createdBy: $foreignUser->getKey(),
        );

        $row = $this->movements($this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '640.00'))[0];

        $this->assertSame('640.00', $row['amount']);
        $this->assertNull($row['created_by'], 'A foreign operator must not be disclosed.');
    }

    /**
     * The operator of a movement is named when they belong to this business.
     *
     * @test
     */
    public function a_movement_names_its_own_businesss_operator(): void
    {
        $this->movement(
            CashRegisterTransaction::TYPE_CASH_IN,
            '75.00',
            $this->register(),
            createdBy: $this->user->getKey(),
        );

        $row = $this->movements($this->report())[0];

        $this->assertSame($this->user->getKey(), $row['created_by']['id']);
        $this->assertSame($this->user->name, $row['created_by']['name']);
    }

    /**
     * A movement with no operator at all is reported, with a null author.
     *
     * `created_by` is `nullOnDelete`, so removing a user leaves exactly this row.
     *
     * @test
     */
    public function a_movement_with_no_operator_is_reported_with_a_null_author(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '20.00');

        $row = $this->movements($this->report())[0];

        $this->assertNull($row['created_by']);
        $this->assertSame('20.00', $row['amount']);
    }

    /**
     * Reading the report writes nothing — it is a read-only projection.
     *
     * @test
     */
    public function reading_the_report_leaves_the_ledger_untouched(): void
    {
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '90.00', $this->register());

        // Read straight off the database rather than through the model, so the
        // snapshot is the stored rows themselves and a write cannot hide behind a
        // stale in-memory cast.
        $columns = ['id', 'type', 'amount', 'reference_type', 'reference_id', 'note', 'created_by', 'created_at'];
        $before = DB::table('cash_register_transactions')->get($columns)->map(
            fn (object $row): array => (array) $row
        )->all();

        $this->report()->assertStatus(200);

        $this->assertSame(
            $before,
            DB::table('cash_register_transactions')->get($columns)->map(
                fn (object $row): array => (array) $row
            )->all(),
        );
    }

    // ------------------------------------------------------------------
    // Movement listing
    // ------------------------------------------------------------------

    /**
     * A listed movement carries exactly the fields the schema can source.
     *
     * Every key here is real: no register name (the column does not exist), no
     * invented description of the referenced document, no made-up operator label.
     *
     * @test
     */
    public function a_movement_row_carries_only_reliably_sourced_fields(): void
    {
        $register = $this->register('250.00');
        $movement = $this->movement(
            CashRegisterTransaction::TYPE_SALE,
            '640.00',
            $register,
            referenceType: Payment::class,
            referenceId: 4242,
            createdBy: $this->user->getKey(),
        );

        $row = $this->movements($this->report())[0];

        $this->assertSame($movement->getKey(), $row['id']);
        $this->assertSame($register->getKey(), $row['cash_register_id']);
        $this->assertSame(CashRegisterTransaction::TYPE_SALE, $row['type']);
        $this->assertSame('in', $row['direction']);
        $this->assertSame('automatic', $row['origin']);
        $this->assertSame('640.00', $row['amount']);
        $this->assertSame(Payment::class, $row['reference_type']);
        $this->assertSame(4242, $row['reference_id']);
        $this->assertArrayHasKey('occurred_at', $row);

        // Nothing is published that the schema cannot back.
        foreach (['register_name', 'description', 'product_name', 'customer_name'] as $invented) {
            $this->assertArrayNotHasKey($invented, $row);
        }
        $this->assertArrayNotHasKey('name', $row['register']);
    }

    /**
     * The register block carries only what `cash_registers` actually stores.
     *
     * @test
     */
    public function the_register_block_exposes_the_registers_own_stored_state(): void
    {
        $register = $this->register('750.00');
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register);

        $block = $this->movements($this->report())[0]['register'];

        $this->assertSame($register->getKey(), $block['id']);
        $this->assertSame(CashRegister::STATUS_OPEN, $block['status']);
        $this->assertSame('750.00', $block['opening_balance']);
        $this->assertNotNull($block['opened_at']);
    }

    /**
     * The opening float is shown as context and never counted as cash in.
     *
     * The pair of assertions is the point: the figure is genuinely available and
     * genuinely useful, and it is still not an inflow.
     *
     * @test
     */
    public function the_opening_float_is_context_only_and_never_a_cash_in(): void
    {
        $register = $this->register('5000.00');
        $this->movement(CashRegisterTransaction::TYPE_OPENING, '5000.00', $register);
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '25.00', $register);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.total_cash_in', '25.00')
            ->assertJsonPath('data.metrics.transaction_count', 2);

        foreach ($this->movements($this->report()) as $movement) {
            $this->assertSame('5000.00', $movement['register']['opening_balance']);
        }
        $this->assertSame(CashRegisterTransaction::TYPE_CASH_IN, $this->movements($this->report())[0]['type']);
    }

    /**
     * The listing is newest first, with the id breaking a timestamp tie.
     *
     * `CashRegisterService::transactions()` orders the drawer history the same way,
     * so the two screens can never list the same movements in different sequences.
     *
     * @test
     */
    public function the_listing_is_ordered_newest_first_with_an_id_tie_break(): void
    {
        $register = $this->register();
        // Three movements in the same second: only the id tie-break can order these.
        $first = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, self::DAY.' 10:00:00');
        $second = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '20.00', $register, self::DAY.' 10:00:00');
        $third = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '30.00', $register, self::DAY.' 10:00:00');
        $later = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '40.00', $register, self::DAY.' 11:00:00');

        $this->assertSame(
            [$later->getKey(), $third->getKey(), $second->getKey(), $first->getKey()],
            array_column($this->movements($this->report()), 'id'),
        );
    }

    /**
     * Two identical requests return the listing in exactly the same order.
     *
     * @test
     */
    public function the_listing_order_is_stable_across_requests(): void
    {
        $register = $this->register();
        foreach (range(1, 6) as $n) {
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, (string) $n, $register);
        }

        $first = array_column($this->movements($this->report()), 'id');
        $second = array_column($this->movements($this->report()), 'id');

        $this->assertSame($first, $second);
    }

    /**
     * `occurred_at` is the movement's own timestamp, and it is the sort key.
     *
     * @test
     */
    public function occurred_at_is_the_movements_own_recorded_timestamp(): void
    {
        $register = $this->register();
        $movement = $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $register, self::DAY.' 09:30:00');

        $this->assertSame(
            Carbon::parse($movement->created_at)->toIso8601String(),
            $this->movements($this->report())[0]['occurred_at'],
        );
    }

    /**
     * The listing is never silently truncated to a top N.
     *
     * Reports here publish one aggregate payload with no pagination, and the totals
     * cover the *complete* filtered dataset. A hidden cap would put a total above a
     * truncated list and the two would silently disagree.
     *
     * @test
     */
    public function the_listing_is_not_truncated_to_a_top_n(): void
    {
        $register = $this->register();
        foreach (range(1, 40) as $n) {
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '1.00', $register);
        }

        $response = $this->report()->assertStatus(200);

        $this->assertCount(40, $this->movements($response));
        $this->assertSame(40, $response->json('data.metrics.transaction_count'));
        // 40 x 1.00 summed from the listing reproduces the header exactly.
        $this->assertSame('40.00', $response->json('data.metrics.total_cash_in'));
    }

    /**
     * Money arrives as two-decimal strings and counts as integers.
     *
     * @test
     */
    public function the_payload_uses_the_established_money_and_count_dialects(): void
    {
        $register = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.5', $register);
        $this->movement(CashRegisterTransaction::TYPE_EXPENSE, '0.1', $register);

        $response = $this->report()->assertStatus(200);

        $this->assertSame('10.50', $response->json('data.metrics.total_cash_in'));
        $this->assertSame('0.10', $response->json('data.metrics.total_cash_out'));
        $this->assertSame('10.40', $response->json('data.metrics.net_cash_movement'));
        $this->assertIsInt($response->json('data.metrics.transaction_count'));
    }

    // ------------------------------------------------------------------
    // Performance
    // ------------------------------------------------------------------

    /**
     * The report costs the same number of queries however many movements there are.
     *
     * Checked against the query log rather than by counting rows, because the thing
     * that actually goes wrong is an N+1: a per-row register lookup or per-user
     * lookup that is invisible in the response but linear in the data. Both the
     * register and the operator arrive on the join the listing already had to make.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_movements(): void
    {
        $few = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '10.00', $few, createdBy: $this->user->getKey());

        // Warm-up: the very first request of a test also resolves the token, the user
        // and the permission graph, and those one-off lookups would otherwise be
        // charged to whichever count happened to run first — making the *smaller*
        // dataset look dearer for reasons that have nothing to do with the report.
        $this->report()->assertStatus(200);

        $small = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $many = $this->register();
        for ($n = 0; $n < 60; $n++) {
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '1.00', $many, createdBy: $this->user->getKey());
        }

        $large = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $this->assertSame(
            $small,
            $large,
            'A report over 60 movements must cost the same queries as one over 1.'
        );
    }

    /**
     * Many movements across many registers still cost a constant number of queries.
     *
     * The stronger form of the check above: a per-register lookup would be constant
     * for one drawer holding everything, and linear once the drawers are spread out.
     *
     * @test
     */
    public function many_registers_do_not_introduce_a_per_register_query(): void
    {
        for ($r = 0; $r < 12; $r++) {
            $register = $this->register();
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '5.00', $register, createdBy: $this->user->getKey());
        }

        // Warm-up, for the same reason as the test above.
        $this->report()->assertStatus(200);

        $spread = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $single = $this->register();
        $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '60.00', $single, createdBy: $this->user->getKey());

        $together = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $this->assertSame(
            $together,
            $spread,
            'Twelve registers must not cost more queries than one.'
        );
    }

    /**
     * One request is a fixed, small number of aggregates — never one per row type.
     *
     * @test
     */
    public function the_report_is_a_fixed_small_number_of_queries(): void
    {
        $register = $this->register();
        foreach (CashRegisterTransaction::TYPES as $index => $type) {
            $this->movement($type, (string) (10 + $index), $register, createdBy: $this->user->getKey());
        }

        $queries = $this->countQueries(fn () => $this->report()->assertStatus(200));

        // Summary + breakdown + listing, plus the auth/tenant lookups Sanctum and the
        // permission gate make. The point is that it is bounded and small, not that it
        // equals one exact integer — the surrounding middleware legitimately adds its
        // own. An N+1 over seven types would push this far higher.
        $this->assertLessThanOrEqual(
            12,
            $queries,
            "Expected a constant handful of queries, got {$queries}."
        );
    }

    /**
     * Reading the report twice costs the same both times.
     *
     * @test
     */
    public function repeated_reads_are_equally_cheap(): void
    {
        $register = $this->register();
        foreach (range(1, 25) as $n) {
            $this->movement(CashRegisterTransaction::TYPE_CASH_IN, '1.00', $register);
        }

        $this->report()->assertStatus(200);

        $first = $this->countQueries(fn () => $this->report()->assertStatus(200));
        $second = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $this->assertSame($first, $second);
    }
}
