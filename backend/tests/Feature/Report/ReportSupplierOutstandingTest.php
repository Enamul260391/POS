<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Permission\PermissionService;
use App\Services\Report\ReportService;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 8 — **supplier outstanding / payables**, `GET /reports/supplier-outstanding`.
 *
 * The payables mirror of Stage 7, and it pins the same properties on the other
 * side of the ledger. The ones that can each hold while the others are broken:
 *
 *  - **The balance is `suppliers.opening_balance + SUM(purchases.due_amount)`,
 *    grouped by supplier.** The `due_amount` header column *is* a current figure
 *    on this schema — `PurchaseService::create()` and `update()` both recompute it
 *    as `grand_total - paid_amount` — so unlike the customer side there is no
 *    ledger to read and none is invented.
 *  - **Current balance is independent of the date filter.** The balance is a
 *    standing position; `date_from` / `date_to` narrow only the separately named
 *    `period_movement`. Asserted directly by comparing two wildly different
 *    windows, because a report that quietly turned "what do we owe" into "what
 *    did we owe last month" would still look entirely plausible.
 *  - **Positive and negative balances are classified, not merged.** `payable` is
 *    the sum of positive balances, `supplier_credit` the *magnitude* of the
 *    negative ones, and `net_outstanding` their difference.
 *  - **Zero balances are omitted from the listing** and cannot move a total.
 *  - **The opening balance is counted exactly once**, and a supplier with an
 *    opening balance but no purchase is still a genuine payable.
 *  - **Tenant isolation is asserted at both layers** — a foreign supplier is
 *    excluded, and a *local* supplier joined against a foreign purchase naming it
 *    cannot leak that purchase's amount, because `purchases.supplier_id` carries
 *    no tenant of its own.
 *  - **The cost is a constant number of grouped aggregates**, proven by
 *    inspecting the query log as supplier count grows rather than by counting
 *    result rows.
 *  - **`reports.view` alone is the whole permission**, and supplier management is
 *    neither required nor sufficient.
 *
 * A note on `supplier_credit`: nothing in the API can currently produce a
 * negative balance (`paid_amount` is capped at `grand_total`, `opening_balance`
 * is `min:0`). The classification is therefore exercised by writing a negative
 * `due_amount` directly — which the `decimal(15,2)` column genuinely permits — so
 * the rule is proven correct rather than merely left unexercised. That is a
 * statement about the *report*, not the invention of a new write path.
 *
 * @see ReportService::supplierOutstandingReport()
 */
class ReportSupplierOutstandingTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day most boundary tests are written around. */
    private const DAY = '2026-01-15';

    /** A day before {@see self::DAY}, used to prove the window really binds. */
    private const EARLIER_DAY = '2026-01-10';

    private Business $business;

    /** A second business, created on first use by the isolation fixtures. */
    private ?Business $otherBusiness = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->actingAsBusinessUser($this->business);
    }
    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * A supplier, optionally of another business.
     *
     * Created through the model rather than `POST /suppliers` so the fixture is
     * exactly the stored row: no purchase and no payment against it.
     */
    private function supplier(string $name, string $openingBalance = '0.00', ?int $businessId = null): Supplier
    {
        return Supplier::factory()->create([
            'business_id' => $businessId ?? $this->business->id,
            'name' => $name,
            'opening_balance' => $openingBalance,
        ]);
    }

    /**
     * One purchase invoice, dated explicitly.
     *
     * The header columns are set the way `PurchaseService` writes them —
     * `due_amount = grand_total - paid_amount` — so the fixture *is* the schema's
     * source of truth rather than a re-derivation of it.
     */
    private function purchase(
        Supplier $supplier,
        string $total,
        string $paid = '0.00',
        ?string $day = null,
        ?int $businessId = null,
    ): Purchase {
        $total = Money::format($total);
        $paid = Money::format($paid);

        return Purchase::factory()->create([
            'business_id' => $businessId ?? $this->business->id,
            'supplier_id' => $supplier->getKey(),
            'purchase_date' => $day ?? self::DAY,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_amount' => $paid,
            'due_amount' => Money::subtract($total, $paid),
            'payment_status' => Purchase::PAYMENT_STATUS_PARTIAL,
        ]);
    }

    /**
     * A payable of `amount` on `day`, on a fresh supplier.
     *
     * An unpaid invoice, so its whole value is still owed.
     */
    private function payableOn(string $name, string $amount, string $day = self::DAY): Supplier
    {
        $supplier = $this->supplier($name);
        $this->purchase($supplier, $amount, '0.00', $day);

        return $supplier;
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
     * `GET /reports/supplier-outstanding`, optionally with a window or filter.
     *
     * @param  array<string, string|int>  $query
     */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/supplier-outstanding'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** `metrics.suppliers` exactly as the payload carries it. */
    private function suppliers(TestResponse $response): array
    {
        return $response->json('data.metrics.suppliers') ?? [];
    }

    /** One row indexed by supplier, for readable assertions. */
    private function rowFor(TestResponse $response, Supplier $supplier): array
    {
        foreach ($this->suppliers($response) as $row) {
            if ($row['supplier_id'] === $supplier->getKey()) {
                return $row;
            }
        }

        return [];
    }

    /**
     * The listing reduced to its *position* fields.
     *
     * `period_movement` is deliberately excluded: it is the one figure the window
     * is allowed to change, so a test asserting that "the window changes nothing"
     * has to leave it out or it is asserting its opposite.
     *
     * @return array<int, array{supplier_id: int, supplier_name: string, balance: string, status: string}>
     */
    private function positionOf(TestResponse $response): array
    {
        return array_map(
            static fn (array $row): array => [
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'balance' => $row['balance'],
                'status' => $row['status'],
            ],
            $this->suppliers($response)
        );
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
            Route::has('api.v1.reports.supplier-outstanding'),
            'The route is registered under its own name.'
        );

        $this->payableOn('OfficeMart Supplies', '500.00');

        $this->report()->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Supplier outstanding report generated successfully',
        ]);
    }

    /**
     * A user holding `reports.view` can read the report.
     *
     * @test
     */
    public function reports_view_grants_access(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $this->report()->assertStatus(200);
    }

    /**
     * The report is closed to an anonymous caller.
     *
     * @test
     */
    public function an_unauthenticated_caller_is_refused(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        auth()->forgetGuards();

        $this->withToken('')->report()->assertStatus(401);
    }

    /**
     * `reports.view` is required — an authenticated user without it gets 403.
     *
     * @test
     */
    public function an_authenticated_user_without_reports_view_is_forbidden(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $this->userWithoutPermissions($this->business->id);

        $this->report()->assertStatus(403);
    }

    /**
     * Supplier management does **not** substitute for `reports.view`.
     *
     * Read access to the supplier records says nothing about read access to the
     * payables, so a role that can open the supplier screen is still refused the
     * report — and the same role pattern with `reports.view` is allowed.
     *
     * @test
     */
    public function supplier_management_does_not_substitute_for_reports_view(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $this->userWithPermissions([PermissionService::SUPPLIERS_VIEW], $this->business->id);

        $this->report()->assertStatus(403);

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $this->report()->assertStatus(200);
    }
    // ------------------------------------------------------------------
    // Core accounting semantics
    // ------------------------------------------------------------------

    /**
     * The balance is what is still owed on the supplier's invoices.
     *
     * @test
     */
    public function it_reports_the_payable_balance_per_supplier(): void
    {
        $supplier = $this->payableOn('OfficeMart Supplies', '17640.00');

        $response = $this->report();

        $response->assertStatus(200)->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 1,
                    'payable' => '17640.00',
                    'supplier_credit' => '0.00',
                    'net_outstanding' => '17640.00',
                ],
            ],
        ]);

        $this->assertSame([
            [
                'supplier_id' => $supplier->getKey(),
                'supplier_name' => 'OfficeMart Supplies',
                'balance' => '17640.00',
                'status' => 'payable',
            ],
        ], $this->positionOf($response));
    }

    /**
     * A partly-paid invoice contributes only the part that is still owed.
     *
     * This is the property that makes `due_amount` a usable source here: the
     * column is rewritten by every write that moves it, so the unpaid remainder
     * is what the register must report rather than the invoice total.
     *
     * @test
     */
    public function a_partly_paid_invoice_contributes_only_its_unpaid_remainder(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $this->purchase($supplier, '44100.00', '26460.00');

        $response = $this->report();

        $response->assertJsonPath('data.metrics.payable', '17640.00');
        $this->assertSame('17640.00', $this->rowFor($response, $supplier)['balance']);
    }

    /**
     * Several invoices against one supplier are summed into a single position.
     *
     * @test
     */
    public function several_invoices_against_one_supplier_are_summed(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $this->purchase($supplier, '100.00', '0.00', self::EARLIER_DAY);
        $this->purchase($supplier, '250.50', '0.00', self::DAY);

        $response = $this->report();

        $response->assertJson([
            'data' => ['metrics' => ['supplier_count' => 1, 'payable' => '350.50']],
        ]);

        $this->assertCount(1, $this->suppliers($response), 'The invoices group into one row.');
    }

    /**
     * The opening balance is counted once, on top of the invoices.
     *
     * @test
     */
    public function the_opening_balance_is_counted_exactly_once(): void
    {
        $supplier = $this->supplier('FreshGro Foods', '500.00');
        $this->purchase($supplier, '200.00', '0.00');

        $response = $this->report();

        $response->assertJsonPath('data.metrics.payable', '700.00');
        $this->assertSame('700.00', $this->rowFor($response, $supplier)['balance']);
    }

    /**
     * A supplier with an opening balance and no purchase is still a payable.
     *
     * The join is a LEFT join, so such an account is listed rather than silently
     * reported as settled — the one direction that would *understate* what the
     * business owes.
     *
     * @test
     */
    public function a_supplier_with_an_opening_balance_and_no_purchase_is_still_listed(): void
    {
        $supplier = $this->supplier('FreshGro Foods', '750.00');

        $response = $this->report();

        $response->assertJsonPath('data.metrics.payable', '750.00');
        $this->assertSame('750.00', $this->rowFor($response, $supplier)['balance']);
    }

    /**
     * A fully settled supplier is omitted from the listing and moves no total.
     *
     * @test
     */
    public function a_settled_supplier_is_omitted_from_the_listing(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $settled = $this->supplier('FreshGro Foods');
        $this->purchase($settled, '43200.00', '43200.00');

        $response = $this->report();

        $response->assertJson([
            'data' => [
                'metrics' => [
                    // Only the unsettled supplier is counted…
                    'supplier_count' => 1,
                    // …and the settled invoice moves neither total.
                    'payable' => '500.00',
                    'supplier_credit' => '0.00',
                    'net_outstanding' => '500.00',
                ],
            ],
        ]);

        $this->assertSame(
            ['OfficeMart Supplies'],
            array_column($this->suppliers($response), 'supplier_name'),
            'A settled supplier is not outstanding and is not listed.',
        );
    }

    /**
     * A supplier with nothing owed at all is omitted too.
     *
     * @test
     */
    public function a_supplier_with_nothing_owed_is_omitted(): void
    {
        $this->supplier('FreshGro Foods');

        $response = $this->report();

        $response->assertJson([
            'data' => [
                'metrics' => ['supplier_count' => 0, 'payable' => '0.00', 'net_outstanding' => '0.00'],
            ],
        ]);

        $this->assertSame([], $this->suppliers($response));
    }

    /**
     * Multiple suppliers are each listed, and the summary covers all of them.
     *
     * @test
     */
    public function it_covers_every_supplier_and_summarises_them_all(): void
    {
        $this->payableOn('OfficeMart Supplies', '1000.00');
        $this->payableOn('TechSource Distribution', '2000.00');
        $this->payableOn('FreshGro Foods', '3000.00');

        $response = $this->report();

        // Ordered by magnitude, not by insertion: the register puts the largest
        // payable first, which is what a reader scanning the list needs.
        $this->assertSame(
            ['FreshGro Foods', 'TechSource Distribution', 'OfficeMart Supplies'],
            array_column($this->suppliers($response), 'supplier_name'),
        );

        $response->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 3,
                    'payable' => '6000.00',
                    'supplier_credit' => '0.00',
                    'net_outstanding' => '6000.00',
                ],
            ],
        ]);
    }

    /**
     * Twenty invoices group into one supplier row and one summary total.
     *
     * The listing is unbounded, so what is really being pinned is that the
     * totals come from the grouped aggregate rather than from a re-sum of
     * whatever rows happened to be returned.
     *
     * @test
     */
    public function the_summary_is_not_computed_from_a_limited_listing(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');

        foreach (range(1, 20) as $ignored) {
            $this->purchase($supplier, '50.00', '0.00');
        }

        $response = $this->report();

        $this->assertCount(1, $this->suppliers($response), 'The invoices group into one row.');
        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '1000.00');
    }
    // ------------------------------------------------------------------
    // Source of truth
    // ------------------------------------------------------------------

    /**
     * The balance reads `due_amount`, not `grand_total`.
     *
     * Two invoices with different payment states on the same day: a report that
     * reached for the invoice total would report 1000.00 instead of 300.00.
     *
     * @test
     */
    public function the_balance_is_the_due_column_rather_than_the_invoice_total(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $this->purchase($supplier, '700.00', '700.00');
        $this->purchase($supplier, '300.00', '0.00');

        $response = $this->report();

        $response->assertJsonPath('data.metrics.payable', '300.00');
        $this->assertSame('300.00', $this->rowFor($response, $supplier)['balance']);
    }

    /**
     * `payments` rows are deliberately not consulted.
     *
     * `payments.purchase_id` is `null` on every row the application writes — no
     * purchase-payment endpoint exists — so summing it would add a column that is
     * zero everywhere while implying a second source of truth. Planting one here
     * proves the report ignores it rather than merely finding it empty.
     *
     * @test
     */
    public function it_does_not_sum_payments_rows_against_a_purchase(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $purchase = $this->purchase($supplier, '800.00', '0.00');

        Payment::create([
            'business_id' => $this->business->id,
            'purchase_id' => $purchase->getKey(),
            'payment_method' => Payment::PAYMENT_METHOD_BANK,
            'amount' => '500.00',
        ]);

        $this->report()->assertJsonPath('data.metrics.payable', '800.00');
    }

    /**
     * The report is read-only.
     *
     * @test
     */
    public function it_writes_nothing(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $this->purchase($supplier, '300.00', '100.00');

        $snapshot = fn (): array => Purchase::query()
            ->forBusiness($this->business->id)
            ->get()
            ->map(fn (Purchase $p): array => [$p->due_amount, $p->paid_amount, (string) $p->updated_at])
            ->all();

        $before = $snapshot();

        $this->report()->assertStatus(200);

        $this->assertSame($before, $snapshot(), 'The report wrote nothing.');
    }

    // ------------------------------------------------------------------
    // Supplier credit and net outstanding
    // ------------------------------------------------------------------

    /**
     * A negative balance is classified as credit, not as a negative payable.
     *
     * The API cannot produce one today (`paid_amount` is capped at
     * `grand_total`, `opening_balance` is `min:0`), so the negative is written
     * directly — which the column permits. The point is that the *rule* is right.
     *
     * @test
     */
    public function a_negative_balance_is_reported_as_supplier_credit(): void
    {
        $supplier = $this->supplier('FreshGro Foods');
        $this->purchase($supplier, '-250.00', '0.00');

        $response = $this->report();

        $response->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 1,
                    'payable' => '0.00',
                    // Reported as a magnitude, never as negative payables.
                    'supplier_credit' => '250.00',
                    'net_outstanding' => '-250.00',
                ],
            ],
        ]);

        $this->assertSame(
            [[
                'supplier_id' => $supplier->getKey(),
                'supplier_name' => 'FreshGro Foods',
                // Signed: the row must not contradict the arithmetic it came from.
                'balance' => '-250.00',
                'status' => 'supplier_credit',
            ]],
            $this->positionOf($response),
        );
    }

    /**
     * Payable and credit are separated and the net is their difference.
     *
     * @test
     */
    public function payable_and_credit_are_separated_and_netted(): void
    {
        $this->payableOn('OfficeMart Supplies', '1000.00');
        $this->payableOn('TechSource Distribution', '400.00');

        $credit = $this->supplier('FreshGro Foods');
        $this->purchase($credit, '-150.00', '0.00');

        $this->report()->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 3,
                    'payable' => '1400.00',
                    'supplier_credit' => '150.00',
                    'net_outstanding' => '1250.00',
                ],
            ],
        ]);
    }

    /**
     * Payables are listed before credits, each group ordered by magnitude.
     *
     * A plain `balance DESC` would sort credits by the raw number, putting a
     * -125 credit above a -750 one — the largest credit last, where the smallest
     * liability reads as the biggest.
     *
     * @test
     */
    public function payables_are_listed_before_credits_ordered_by_magnitude(): void
    {
        $this->payableOn('Small Payable', '100.00');
        $this->payableOn('Large Payable', '900.00');

        foreach (['Small Credit' => '-125.00', 'Large Credit' => '-750.00'] as $name => $amount) {
            $supplier = $this->supplier($name);
            $this->purchase($supplier, $amount, '0.00');
        }

        $this->assertSame(
            ['Large Payable', 'Small Payable', 'Large Credit', 'Small Credit'],
            array_column($this->suppliers($this->report()), 'supplier_name'),
        );
    }
    // ------------------------------------------------------------------
    // Date semantics
    // ------------------------------------------------------------------

    /**
     * The period wrapper echoes exactly what was sent.
     *
     * @test
     */
    public function the_period_is_echoed_back_verbatim(): void
    {
        $this->assertSame(
            ['from' => '2026-01-01', 'to' => '2026-01-31'],
            $this->report(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])->json('data.period'),
        );

        $this->assertSame(
            ['from' => null, 'to' => null],
            $this->report()->json('data.period'),
        );
    }

    /**
     * `date_from` does not narrow the current balance.
     *
     * The window is a reporting-period filter, not an "as at" filter, so moving
     * it cannot make a supplier's standing payable appear to change.
     *
     * @test
     */
    public function the_current_balance_is_not_narrowed_by_date_from(): void
    {
        $this->payableOn('OfficeMart Supplies', '17640.00', self::EARLIER_DAY);
        $this->payableOn('TechSource Distribution', '5000.00', self::DAY);

        $filtered = $this->report(['date_from' => self::DAY]);

        $this->assertSame(
            $this->positionOf($this->report()),
            $this->positionOf($filtered),
            'Both suppliers stay outstanding whatever the window.',
        );
        $filtered->assertJsonPath('data.metrics.payable', '22640.00');
    }

    /**
     * `date_to` does not narrow the current balance either.
     *
     * @test
     */
    public function the_current_balance_is_not_narrowed_by_date_to(): void
    {
        $this->payableOn('OfficeMart Supplies', '17640.00', self::DAY);
        $this->payableOn('TechSource Distribution', '5000.00', self::EARLIER_DAY);

        $filtered = $this->report(['date_to' => self::DAY]);

        $this->assertSame($this->positionOf($this->report()), $this->positionOf($filtered));
        $filtered->assertJsonPath('data.metrics.payable', '22640.00');
    }

    /**
     * An entirely empty window leaves the balance untouched.
     *
     * @test
     */
    public function an_empty_window_leaves_the_current_balance_untouched(): void
    {
        $this->payableOn('OfficeMart Supplies', '17640.00', self::DAY);

        $response = $this->report(['date_from' => '2020-01-01', 'date_to' => '2020-12-31']);

        $response->assertJsonPath('data.metrics.payable', '17640.00');
        $this->assertSame('17640.00', $response->json('data.metrics.suppliers.0.balance'));
    }

    /**
     * An invoice dated exactly on `from` is inside the movement window.
     *
     * An exclusive lower bound would drop the first day of every period.
     *
     * @test
     */
    public function an_invoice_on_the_from_boundary_is_inside_the_window(): void
    {
        $supplier = $this->payableOn('OfficeMart Supplies', '300.00', self::DAY);

        $response = $this->report(['date_from' => self::DAY, 'date_to' => self::DAY]);

        $response->assertJsonPath('data.metrics.period_movement', '300.00');
        $this->assertSame('300.00', $this->rowFor($response, $supplier)['period_movement']);
    }

    /**
     * An invoice dated exactly on `to` is inside the window too.
     *
     * @test
     */
    public function an_invoice_on_the_to_boundary_is_inside_the_window(): void
    {
        $this->payableOn('OfficeMart Supplies', '300.00', self::DAY);

        $this->report(['date_from' => self::EARLIER_DAY, 'date_to' => self::DAY])
            ->assertJsonPath('data.metrics.period_movement', '300.00');
    }

    /**
     * An invoice dated immediately before `from` is outside the window.
     *
     * @test
     */
    public function an_invoice_before_the_from_boundary_is_excluded_from_the_movement(): void
    {
        $this->payableOn('OfficeMart Supplies', '300.00', self::EARLIER_DAY);

        $response = $this->report(['date_from' => self::DAY, 'date_to' => self::DAY]);

        // Excluded from the movement…
        $response->assertJsonPath('data.metrics.period_movement', '0.00');
        $response->assertJsonPath('data.metrics.suppliers.0.period_movement', '0.00');

        // …but still part of the standing balance, which the window cannot touch.
        $response->assertJsonPath('data.metrics.payable', '300.00');
    }

    /**
     * An invoice dated immediately after `to` is outside the window.
     *
     * @test
     */
    public function an_invoice_after_the_to_boundary_is_excluded_from_the_movement(): void
    {
        $this->payableOn('OfficeMart Supplies', '300.00', '2026-01-16');

        $response = $this->report(['date_from' => self::EARLIER_DAY, 'date_to' => self::DAY]);

        $response->assertJsonPath('data.metrics.period_movement', '0.00');
        $response->assertJsonPath('data.metrics.payable', '300.00');
    }

    /**
     * Both boundaries are inclusive, so a closed window captures exactly its days.
     *
     * @test
     */
    public function the_window_is_inclusive_at_both_ends(): void
    {
        $this->payableOn('First Day', '100.00', self::EARLIER_DAY);
        $this->payableOn('Middle Day', '200.00', self::DAY);
        $this->payableOn('Last Day', '400.00', '2026-01-17');
        $this->payableOn('After', '800.00', '2026-01-18');
        $this->payableOn('Before', '1600.00', '2026-01-09');

        $response = $this->report(['date_from' => self::EARLIER_DAY, 'date_to' => '2026-01-17']);

        // 100 + 200 + 400 = 700, with both adjacent days excluded.
        $response->assertJsonPath('data.metrics.period_movement', '700.00');
        // The balance is the whole history, unfiltered.
        $response->assertJsonPath('data.metrics.payable', '3100.00');
    }

    /**
     * A one-day window on a day with no invoices moves nothing.
     *
     * @test
     */
    public function an_empty_window_yields_a_zero_movement(): void
    {
        $this->payableOn('OfficeMart Supplies', '300.00', self::DAY);

        $response = $this->report(['date_from' => '2026-03-01', 'date_to' => '2026-03-01']);

        $response->assertJsonPath('data.metrics.period_movement', '0.00');
        $response->assertJsonPath('data.metrics.payable', '300.00');
    }

    /**
     * With no window at all the movement is the whole history.
     *
     * `1 = 1` is the honest reading of an unbounded request, not a default period.
     *
     * @test
     */
    public function no_date_window_reports_the_whole_history_movement(): void
    {
        $this->payableOn('First', '100.00', '2026-01-09');
        $this->payableOn('Second', '250.00', self::DAY);
        $this->payableOn('Third', '400.00', '2026-02-20');

        $response = $this->report();

        $response->assertJsonPath('data.metrics.period_movement', '750.00');

        // Each row's movement is that supplier's own invoices inside the
        // open window, so the three amounts appear one per row and the summary
        // is their total.
        $this->assertSame(
            ['400.00', '250.00', '100.00'],
            array_column($this->suppliers($response), 'period_movement'),
        );
    }

    /**
     * An open-ended window leaves that side unbound.
     *
     * @test
     */
    public function an_absent_bound_leaves_that_side_of_the_window_open(): void
    {
        $this->payableOn('Older', '300.00', '2026-01-05');
        $this->payableOn('Newer', '700.00', '2026-05-05');

        // Only `date_to`: everything up to and including it.
        $this->report(['date_to' => '2026-02-01'])
            ->assertJsonPath('data.metrics.period_movement', '300.00');

        // Only `date_from`: everything from it onwards.
        $this->report(['date_from' => '2026-02-01'])
            ->assertJsonPath('data.metrics.period_movement', '700.00');
    }

    /**
     * A malformed or inverted window is rejected on the established rule.
     *
     * @test
     */
    public function a_malformed_window_is_rejected(): void
    {
        $this->report(['date_from' => 'not-a-date'])->assertStatus(422);
        $this->report(['date_from' => '2026-02-01', 'date_to' => '2026-01-01'])->assertStatus(422);
    }
    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's supplier is never listed.
     *
     * @test
     */
    public function a_foreign_supplier_is_not_listed(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $foreign = $this->supplier('Foreign Supplier', '0.00', $this->otherBusinessId());
        $this->purchase($foreign, '9999.00', '0.00', null, $this->otherBusinessId());

        $response = $this->report();

        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '500.00');
        $this->assertSame(
            ['OfficeMart Supplies'],
            array_column($this->suppliers($response), 'supplier_name'),
        );
    }

    /**
     * The same supplier name in two businesses stays two separate accounts.
     *
     * @test
     */
    public function the_same_supplier_name_in_two_businesses_stays_separate(): void
    {
        $mine = $this->supplier('OfficeMart Supplies');
        $this->purchase($mine, '500.00', '0.00');

        $theirs = $this->supplier('OfficeMart Supplies', '0.00', $this->otherBusinessId());
        $this->purchase($theirs, '7500.00', '0.00', null, $this->otherBusinessId());

        $response = $this->report();

        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '500.00');
        $this->assertSame(
            [$mine->getKey()],
            array_column($this->suppliers($response), 'supplier_id'),
            'Only the local supplier row is returned, despite the identical name.',
        );
    }

    /**
     * A foreign purchase naming a *local* supplier cannot leak into its balance.
     *
     * `purchases.supplier_id` is a plain foreign key with no composite constraint
     * tying it to the purchase's own tenant, so scoping only the supplier side
     * would group another business's money against this business's supplier.
     *
     * @test
     */
    public function a_foreign_purchase_naming_a_local_supplier_is_excluded(): void
    {
        $supplier = $this->supplier('OfficeMart Supplies');
        $this->purchase($supplier, '500.00', '0.00');

        // Same supplier id, foreign business, same day.
        $this->purchase($supplier, '9999.00', '0.00', self::DAY, $this->otherBusinessId());

        $response = $this->report();
        $row = $this->rowFor($response, $supplier);

        $response->assertJsonPath('data.metrics.payable', '500.00');
        $this->assertSame('500.00', $row['balance'], 'The foreign amount is not added.');
        $this->assertSame(
            '500.00',
            $row['period_movement'],
            'The foreign row is dropped by the join-side guard, so only the local '
            .'invoice moves the figure — the foreign 9999.00 is nowhere in it.',
        );
    }

    /**
     * A local purchase naming a *foreign* supplier groups nothing here.
     *
     * @test
     */
    public function a_local_purchase_naming_a_foreign_supplier_is_excluded(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $foreign = $this->supplier('Foreign Supplier', '0.00', $this->otherBusinessId());

        // Local business, foreign supplier id.
        $this->purchase($foreign, '4000.00', '0.00', null, $this->business->id);

        $response = $this->report();

        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '500.00');
    }

    /**
     * A soft-deleted supplier leaves the register.
     *
     * A removed supplier is not someone the business still owes, and the report
     * must agree with the supplier screen about whether it still exists.
     *
     * @test
     */
    public function a_soft_deleted_supplier_is_excluded(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $deleted = $this->payableOn('Removed Supplier', '2500.00');
        $deleted->delete();

        $response = $this->report();

        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '500.00');
        $this->assertSame(
            ['OfficeMart Supplies'],
            array_column($this->suppliers($response), 'supplier_name'),
        );
    }
    // ------------------------------------------------------------------
    // Filtering
    // ------------------------------------------------------------------

    /**
     * A valid `supplier_id` narrows the register to that supplier.
     *
     * @test
     */
    public function a_valid_supplier_filter_narrows_the_register(): void
    {
        $wanted = $this->payableOn('OfficeMart Supplies', '500.00');
        $this->payableOn('TechSource Distribution', '9000.00');

        $response = $this->report(['supplier_id' => $wanted->getKey()]);

        $response->assertJsonPath('data.metrics.supplier_count', 1);
        $response->assertJsonPath('data.metrics.payable', '500.00');
        $this->assertSame(
            [$wanted->getKey()],
            array_column($this->suppliers($response), 'supplier_id'),
        );
    }

    /**
     * A foreign `supplier_id` is a 422 on the established validation rule.
     *
     * The filter is tenant-safe because `ReportFilterRequest` already validates
     * it against the caller's own business; this pins that the report inherits
     * that rule rather than inventing a second authorization path.
     *
     * @test
     */
    public function a_foreign_supplier_filter_is_rejected(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $foreign = $this->supplier('Foreign Supplier', '0.00', $this->otherBusinessId());

        $this->report(['supplier_id' => $foreign->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    /**
     * Filtering to a settled supplier is an empty register, not a zero row.
     *
     * @test
     */
    public function filtering_to_a_settled_supplier_returns_an_empty_register(): void
    {
        $this->payableOn('OfficeMart Supplies', '500.00');

        $settled = $this->supplier('FreshGro Foods');
        $this->purchase($settled, '43200.00', '43200.00');

        $this->report(['supplier_id' => $settled->getKey()])->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 0,
                    'payable' => '0.00',
                    'supplier_credit' => '0.00',
                    'net_outstanding' => '0.00',
                    'suppliers' => [],
                ],
            ],
        ]);
    }

    /**
     * A business with no suppliers at all gets a well-formed empty report.
     *
     * @test
     */
    public function a_business_with_no_suppliers_gets_an_empty_but_valid_report(): void
    {
        $this->report()->assertStatus(200)->assertJson([
            'data' => [
                'metrics' => [
                    'supplier_count' => 0,
                    'payable' => '0.00',
                    'supplier_credit' => '0.00',
                    'net_outstanding' => '0.00',
                    'period_movement' => '0.00',
                    'suppliers' => [],
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Performance
    // ------------------------------------------------------------------

    /**
     * The query count does not grow with the number of suppliers.
     *
     * This is the N+1 guard. A per-supplier lookup — or loading every purchase
     * into PHP and summing it — would scale with the register; a grouped
     * aggregate does not.
     *
     * @test
     */
    public function the_query_count_is_constant_as_the_supplier_count_grows(): void
    {
        $small = $this->supplier('Small Supplier');
        $this->purchase($small, '100.00', '0.00');

        // One warm-up request first: the very first HTTP call in a test also
        // resolves the token, the user and the permission graph, which is a
        // one-time framework cost. Measuring it would make the *small* figure
        // look larger than the *large* one for reasons that have nothing to do
        // with the report.
        $this->report()->assertStatus(200);

        $smallQueries = $this->countQueries(fn () => $this->report());

        // Ten more suppliers, each with several invoices.
        foreach (range(1, 10) as $ignored) {
            $supplier = $this->supplier('Supplier '.$ignored);
            foreach (range(1, 3) as $alsoIgnored) {
                $this->purchase($supplier, '50.00', '0.00');
            }
        }

        $largeQueries = $this->countQueries(fn () => $this->report());

        $this->assertSame(
            $smallQueries,
            $largeQueries,
            'Eleven suppliers and thirty-one invoices cost the same queries as one.',
        );

        // And the register really is bigger, so the assertion is not vacuous.
        $this->assertCount(11, $this->suppliers($this->report()));
    }

    /**
     * The whole register is served by a constant, tiny number of queries.
     *
     * @test
     */
    public function the_report_issues_a_fixed_small_number_of_queries(): void
    {
        foreach (range(1, 8) as $ignored) {
            $this->payableOn('Supplier '.$ignored, '120.00');
        }

        // Warm the token/permission resolution so only the report's own
        // statements are counted (see the test above).
        $this->report()->assertStatus(200);

        $queries = $this->countQueries(fn () => $this->report());

        // A ceiling on the *whole request*, generous enough to absorb framework
        // statements but far below anything a per-supplier implementation could
        // hide behind: eight suppliers here, thirty in the test above, and the
        // count is identical.
        $this->assertLessThanOrEqual(8, $queries, 'The request issues a constant number of queries.');
    }

    /**
     * The report is exactly two grouped aggregates: the listing and the summary
     * that wraps it.
     *
     * Counted against the service rather than the HTTP request so the framework's
     * own authentication statements cannot be mistaken for report queries.
     *
     * @test
     */
    public function the_report_itself_is_one_listing_aggregate_and_one_summary_aggregate(): void
    {
        foreach (range(1, 12) as $ignored) {
            $this->payableOn('Supplier '.$ignored, '120.00');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        app(ReportService::class)->supplierOutstandingReport($this->business->id, []);

        $selects = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_starts_with(strtoupper($entry['query']), 'SELECT'),
        ));

        DB::disableQueryLog();

        $this->assertCount(2, $selects, 'The listing, then the summary built on the same grouping.');

        // The summary aggregates the listing's grouped result rather than the
        // source table, which is what lets one number describe every supplier.
        $this->assertStringContainsString('from (select', strtolower($selects[0]['query']));
        $this->assertStringContainsString('sum(purchases.due_amount)', strtolower($selects[1]['query']));
    }

    /**
     * The listing is a register, not a ranking: no top-N is invented.
     *
     * @test
     */
    public function no_limit_is_applied_to_the_listing(): void
    {
        foreach (range(1, 25) as $index) {
            $this->payableOn('Supplier '.$index, '10.00');
        }

        $response = $this->report();

        $this->assertCount(25, $this->suppliers($response));
        $response->assertJsonPath('data.metrics.supplier_count', 25);
        $response->assertJsonPath('data.metrics.payable', '250.00');
    }
}
