<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\Permission\PermissionService;
use App\Services\Report\ReportService;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 7 — **customer outstanding / receivables**, `GET /reports/customer-outstanding`.
 *
 * Everything the report is allowed to be is pinned here. The properties that
 * matter most are the ones that can each hold while the others are broken:
 *
 *  - **The balance is `SUM(customer_ledger_entries.amount)`, grouped by
 *    customer.** `sales.due_amount` is not read, not summed and not used as a
 *    fallback. Several tests below deliberately set the two to different figures
 *    so a regression that reached for the invoice column would be caught.
 *  - **Current balance is independent of the date filter.** The balance is a
 *    standing position; `date_from` / `date_to` narrow only the separately named
 *    `period_movement`. Two requests with wildly different windows must return
 *    identical balances — asserted directly, because a report that quietly turned
 *    "who owes me" into "who owed me last month" would still look plausible.
 *  - **Positive and negative balances are classified, not merged.** `receivable`
 *    is the sum of positive balances, `customer_credit` the *magnitude* of the
 *    negative ones, and `net_outstanding` their difference. Credit is never
 *    reported as a negative receivable and never dropped.
 *  - **Zero balances are omitted from the listing** and cannot move a total.
 *  - **Every existing entry type affects the balance exactly once**, and a refund
 *    is not applied a second time on top of the `sale_return` that already
 *    relieved it.
 *  - **The opening balance is counted once.** `customers.opening_balance` is the
 *    historical original and is *not* added to the ledger sum.
 *  - **Tenant isolation is asserted at both layers** — another business's ledger
 *    is excluded, and a *local* ledger entry pointing at a foreign `customer_id`
 *    cannot leak that customer's id, name or balance either.
 *  - **The cost is a constant number of grouped aggregates**, proven by
 *    inspecting the SQL rather than by counting result rows.
 *  - **`reports.view` alone is the whole permission**, and `customers.view` is
 *    neither required nor sufficient.
 *
 * @see ReportService::customerOutstandingReport()
 */
class ReportCustomerOutstandingTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /** The day most boundary tests are written around. */
    private const DAY = '2026-01-15';

    /** A day before {@see self::DAY}, used to prove the window really binds. */
    private const EARLIER_DAY = '2026-01-10';

    private Business $business;

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
     * A customer of this business.
     *
     * Created through the model rather than `POST /customers` so the fixture
     * writes no `opening_balance` ledger row: tests that care about the opening
     * balance add that row themselves and count it explicitly.
     */
    private function customer(string $name, ?string $openingBalance = null): Customer
    {
        return Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
            'opening_balance' => $openingBalance ?? '0.00',
        ]);
    }

    /**
     * One ledger movement, dated explicitly.
     *
     * `created_at` is set rather than left to `now()` because several tests are
     * about the window: an entry written "today" cannot be tested against a
     * January 2026 window otherwise.
     */
    private function entry(Customer $customer, string $type, string $amount, ?string $createdAt = null): CustomerLedgerEntry
    {
        $entry = CustomerLedgerEntry::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->getKey(),
            'type' => $type,
            'amount' => $amount,
        ]);

        if ($createdAt !== null) {
            $entry->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $entry;
    }

    /** A receivable of `amount` on `day`, on a fresh customer. */
    private function receivableOn(string $name, string $amount, string $day = self::DAY): Customer
    {
        $customer = $this->customer($name);
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, $amount, $day);

        return $customer;
    }

    /** A priced invoice built through the models the HTTP flow writes. */
    private function invoice(Customer $customer, string $total, ?string $day = null): Sale
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => '40.00',
            'selling_price' => '100.00',
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '50',
        ]);

        $sale = Sale::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->getKey(),
            'sale_date' => $day ?? self::DAY,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => $product->cost_price,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
        ]);

        return $sale;
    }

    // ------------------------------------------------------------------
    // Request + extraction helpers
    // ------------------------------------------------------------------

    /**
     * `GET /reports/customer-outstanding`, optionally with a window or filter.
     *
     * @param  array<string, string|int>  $query
     */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/customer-outstanding'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** `metrics.customers` exactly as the payload carries it. */
    private function customers(TestResponse $response): array
    {
        return $response->json('data.metrics.customers') ?? [];
    }

    /** The customer ids, in the order the API returned them. */
    private function order(TestResponse $response): array
    {
        return array_column($this->customers($response), 'customer_id');
    }

    /** One row indexed by customer, for readable assertions. */
    private function rowFor(TestResponse $response, Customer $customer): array
    {
        foreach ($this->customers($response) as $row) {
            if ($row['customer_id'] === $customer->getKey()) {
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
     * has to leave it out of the comparison or it is asserting its opposite.
     *
     * @return array<int, array{customer_id: int, customer_name: string, balance: string, status: string}>
     */
    private function positionOf(TestResponse $response): array
    {
        return array_map(
            static fn (array $row): array => [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'balance' => $row['balance'],
                'status' => $row['status'],
            ],
            $this->customers($response)
        );
    }

    /**
     * The whole tenant ledger, flattened for comparison.
     *
     * A report is read-only, so "it did not write" is a claim about *every*
     * column rather than about the row count: the same rows, in the same order,
     * carrying the same amounts and timestamps.
     *
     * @return array<int, array<int, string>>
     */
    private function ledgerState(): array
    {
        return CustomerLedgerEntry::query()
            ->forBusiness($this->business->id)
            ->get()
            ->map(fn (CustomerLedgerEntry $entry): array => [
                $entry->type,
                $entry->amount,
                (string) $entry->created_at,
            ])->values()->all();
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
            Route::has('api.v1.reports.customer-outstanding'),
            'The route is registered under its own name.'
        );

        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->report()->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Customer outstanding report generated successfully',
        ]);
    }

    /**
     * A user holding `reports.view` can read the report.
     *
     * @test
     */
    public function an_authenticated_reports_view_user_can_access_it(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $this->report()->assertStatus(200);
    }

    /**
     * No token is 401 — never 403, which would confirm the endpoint exists and
     * report an authentication failure under the wrong code.
     *
     * @test
     */
    public function unauthenticated_requests_are_401(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->flushHeaders();

        $this->report()->assertStatus(401);
    }

    /**
     * A valid token without the capability is 403 in the standard envelope.
     *
     * @test
     */
    public function a_user_without_reports_view_is_403(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->userWithoutPermissions($this->business->id);

        $this->report()->assertStatus(403)->assertJson(['success' => false]);
    }

    /**
     * `customers.view` alone is **not** enough.
     *
     * The report is a financial listing, not the customer directory, so holding
     * the customer-management permission must not open it. Asserted explicitly
     * because "just allow customers.view as well" is exactly the wrong fix
     * someone would reach for when the 403 first appears.
     *
     * @test
     */
    public function customers_view_alone_does_not_authorise_the_report(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->userWithPermissions([PermissionService::CUSTOMERS_VIEW], $this->business->id);

        $this->report()->assertStatus(403);
    }

    /**
     * `reports.view` **alone** is enough — no customer permission is required.
     *
     * @test
     */
    public function reports_view_alone_authorises_it_without_any_customer_permission(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.receivable', '500.00');
    }

    /**
     * No new permission is introduced for this report.
     *
     * @test
     */
    public function no_new_permission_is_introduced_for_the_report(): void
    {
        $catalogue = PermissionService::catalogueSlugs();

        $this->assertContains(PermissionService::REPORTS_VIEW, $catalogue);

        foreach ($catalogue as $slug) {
            $this->assertStringNotContainsString('outstanding', $slug);
            $this->assertStringNotContainsString('receivable', $slug);
        }

        // The retired coarse alias stays absent.
        $this->assertNotContains('reports.manage', $catalogue);
    }

    // ------------------------------------------------------------------
    // The ledger is the source of truth
    // ------------------------------------------------------------------

    /**
     * The balance is `SUM(customer_ledger_entries.amount)` — not `due_amount`.
     *
     * The two are deliberately set to different figures: the invoice says 1,000
     * is due, the ledger says 250. A report that reached for the invoice column
     * would report 1,000 and fail here.
     *
     * @test
     */
    public function the_balance_comes_from_the_ledger_sum_not_the_invoice_due_column(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $sale = $this->invoice($customer, '1000.00');

        $this->assertSame('1000.00', Money::format($sale->due_amount));

        // The ledger says something quite different, and the ledger wins.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '250.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertSame('250.00', $response->json('data.metrics.customers.0.balance'));
        $this->assertSame('250.00', $response->json('data.metrics.receivable'));
    }

    /**
     * Several entries aggregate into one balance rather than one row each.
     *
     * @test
     */
    public function multiple_ledger_entries_aggregate_into_a_single_balance(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', self::DAY);
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-250.00', self::DAY);
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_RETURN, '-200.00', self::DAY);
        $this->entry($customer, CustomerLedgerEntry::TYPE_ADJUSTMENT, '50.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertCount(1, $this->customers($response));
        $this->assertSame('600.00', $response->json('data.metrics.customers.0.balance'));
        $this->assertSame('600.00', $response->json('data.metrics.receivable'));
        $this->assertSame(1, $response->json('data.metrics.customer_count'));
    }

    /**
     * `customers.opening_balance` is not added on top of the ledger.
     *
     * The column is the historical original — what the account was opened with —
     * and `CustomerService::create()` already wrote it into the ledger as an
     * `opening_balance` entry. Reading both would double-count every customer in
     * the book, which is why this is a regression test rather than a comment.
     *
     * @test
     */
    public function the_opening_balance_is_counted_exactly_once(): void
    {
        $customer = $this->customer('Rafiq', '1900.00');

        // Exactly what `CustomerService::create()` writes for this customer.
        $this->entry($customer, CustomerLedgerEntry::TYPE_OPENING_BALANCE, '1900.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertSame('1900.00', $response->json('data.metrics.customers.0.balance'));
        $this->assertSame('1900.00', $response->json('data.metrics.receivable'));
    }

    /**
     * Editing the `opening_balance` column afterwards does not move the balance.
     *
     * The column is master data, never a running total; the ledger is what the
     * report reads. If it ever became the source instead, this would double.
     *
     * @test
     */
    public function editing_the_opening_balance_column_does_not_move_the_balance(): void
    {
        $customer = $this->customer('Rafiq', '1900.00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_OPENING_BALANCE, '1900.00', self::DAY);

        $customer->forceFill(['opening_balance' => '5000.00'])->save();

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.customers.0.balance', '1900.00')
            ->assertJsonPath('data.metrics.receivable', '1900.00');
    }

    /**
     * Every documented entry type is applied, each exactly once.
     *
     * Driven from the model's own constants rather than a hand-written list, so a
     * type added to the ledger later is covered here automatically instead of
     * silently going untested.
     *
     * @test
     */
    public function every_ledger_entry_type_affects_the_balance_exactly_once(): void
    {
        $expected = [
            CustomerLedgerEntry::TYPE_OPENING_BALANCE => '500.00',
            CustomerLedgerEntry::TYPE_SALE_DUE => '200.00',
            CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT => '-100.00',
            CustomerLedgerEntry::TYPE_SALE_RETURN => '-50.00',
            CustomerLedgerEntry::TYPE_ADJUSTMENT => '25.00',
        ];

        // The report must account for every type the model actually declares.
        $this->assertSame(array_keys($expected), CustomerLedgerEntry::TYPES);

        $total = Money::format(null);
        $customer = $this->customer('Ayesha Rahman');

        foreach ($expected as $type => $amount) {
            $this->entry($customer, $type, $amount, self::DAY);
            $total = Money::add($total, $amount);
        }

        $this->assertSame('575.00', $total);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.customers.0.balance', '575.00')
            ->assertJsonPath('data.metrics.receivable', '575.00');
    }

    /**
     * A refund is never applied a second time through the ledger.
     *
     * The scenario is the one the ledger was built for: a credit sale of 1,000, a
     * later return of 400 that no refund method could cover, and a later payment
     * of 600. `SaleReturnService` writes the `sale_return` for the unsettled 400
     * only; the refund `Payment` is a separate outbound document that touches the
     * ledger not at all. So the balance is 1,000 − 400 − 600 = 0, and the report
     * must not subtract the refund a second time.
     *
     * @test
     */
    public function a_refund_is_never_applied_twice_through_the_ledger(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        // The credit sale, recorded for its full unpaid amount.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', self::DAY);

        // The return. Only the unsettled part is written; the refunded part was
        // paid out through `SaleReturnService::settleRefund()` and must not also
        // relieve the account.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_RETURN, '-400.00', self::DAY);

        // The later payment, capped by what was genuinely still outstanding.
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-600.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        // Settled exactly, with no double relief and no invented credit.
        $this->assertCount(0, $this->customers($response));
        $this->assertSame('0.00', $response->json('data.metrics.receivable'));
        $this->assertSame('0.00', $response->json('data.metrics.customer_credit'));
        $this->assertSame('0.00', $response->json('data.metrics.net_outstanding'));
    }

    // ------------------------------------------------------------------
    // Classification and the summary
    // ------------------------------------------------------------------

    /**
     * A positive balance is a receivable: the customer owes the business.
     *
     * @test
     */
    public function a_positive_balance_is_classified_as_a_receivable(): void
    {
        $this->receivableOn('Ayesha Rahman', '500.00');

        $this->report()->assertStatus(200)->assertJson([
            'data' => [
                'metrics' => [
                    'customers' => [[
                        'customer_name' => 'Ayesha Rahman',
                        'balance' => '500.00',
                        'status' => 'receivable',
                    ]],
                    'receivable' => '500.00',
                    'customer_credit' => '0.00',
                    'net_outstanding' => '500.00',
                ],
            ],
        ]);
    }

    /**
     * A negative balance is customer credit: the business owes the customer.
     *
     * The balance stays **negative** in the row — the ledger's own sign, not an
     * absolute value — and is named `customer_credit` rather than appearing as a
     * negative receivable.
     *
     * @test
     */
    public function a_negative_balance_is_classified_as_customer_credit(): void
    {
        $customer = $this->customer('Nusrat');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_RETURN, '-750.00', self::DAY);

        $this->report()->assertStatus(200)->assertJson([
            'data' => [
                'metrics' => [
                    'customers' => [[
                        'customer_name' => 'Nusrat',
                        'balance' => '-750.00',
                        'status' => 'customer_credit',
                    ]],
                    'receivable' => '0.00',
                    'customer_credit' => '750.00',
                    'net_outstanding' => '-750.00',
                ],
            ],
        ]);
    }

    /**
     * `receivable` is the sum of positive balances and nothing else.
     *
     * @test
     */
    public function the_summary_receivable_is_the_sum_of_positive_balances(): void
    {
        $this->receivableOn('Imran', '500.00');
        $this->receivableOn('Nusrat', '625.00');
        $this->receivableOn('Rafiq', '1900.00');

        // A credit balance that must not reduce the receivable total.
        $credit = $this->customer('Farhana');
        $this->entry($credit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-125.00', self::DAY);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.receivable', '3025.00')
            ->assertJsonPath('data.metrics.customer_credit', '125.00')
            ->assertJsonPath('data.metrics.net_outstanding', '2900.00');
    }

    /**
     * `customer_credit` is the **magnitude** of the negative balances.
     *
     * Two credits of −300 and −200 must total 500.00, never −500.00: a negative
     * "credit" would read as though the business were owed money it is not.
     *
     * @test
     */
    public function the_summary_customer_credit_is_the_absolute_sum_of_negative_balances(): void
    {
        $first = $this->customer('Farhana');
        $this->entry($first, CustomerLedgerEntry::TYPE_SALE_RETURN, '-300.00', self::DAY);

        $second = $this->customer('Shahana');
        $this->entry($second, CustomerLedgerEntry::TYPE_SALE_RETURN, '-200.00', self::DAY);

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.customer_credit', '500.00')
            ->assertJsonPath('data.metrics.receivable', '0.00')
            ->assertJsonPath('data.metrics.net_outstanding', '-500.00');
    }

    /**
     * `net_outstanding` is receivable minus customer credit.
     *
     * @test
     */
    public function net_outstanding_is_receivable_minus_customer_credit(): void
    {
        $this->receivableOn('Imran', '1000.00');
        $this->receivableOn('Nusrat', '750.00');

        $credit = $this->customer('Farhana');
        $this->entry($credit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-250.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertSame('1750.00', $response->json('data.metrics.receivable'));
        $this->assertSame('250.00', $response->json('data.metrics.customer_credit'));
        $this->assertSame(
            Money::subtract('1750.00', '250.00'),
            $response->json('data.metrics.net_outstanding')
        );
        $this->assertSame('1500.00', $response->json('data.metrics.net_outstanding'));
    }

    /**
     * A settled customer is omitted from the listing and moves no total.
     *
     * @test
     */
    public function a_zero_balance_customer_is_omitted_and_cannot_affect_the_totals(): void
    {
        $this->receivableOn('Imran', '500.00');

        // A customer whose movements cancel out exactly.
        $settled = $this->customer('Settled Customer');
        $this->entry($settled, CustomerLedgerEntry::TYPE_SALE_DUE, '400.00', self::DAY);
        $this->entry($settled, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-400.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertNotContains($settled->id, $this->order($response));
        $this->assertCount(1, $this->customers($response));
        $this->assertSame(1, $response->json('data.metrics.customer_count'));

        // The totals are exactly what they were without the settled customer.
        $this->assertSame('500.00', $response->json('data.metrics.receivable'));
        $this->assertSame('0.00', $response->json('data.metrics.customer_credit'));
        $this->assertSame('500.00', $response->json('data.metrics.net_outstanding'));
    }

    /**
     * A customer with no ledger rows at all is not listed either.
     *
     * @test
     */
    public function a_customer_with_no_ledger_entries_is_not_listed(): void
    {
        $this->customer('Never Bought Anything');
        $this->receivableOn('Imran', '500.00');

        $response = $this->report()->assertStatus(200);

        $this->assertCount(1, $this->customers($response));
        $this->assertSame(1, $response->json('data.metrics.customer_count'));
    }

    /**
     * A business with nothing outstanding gets an empty list and zero totals.
     *
     * @test
     */
    public function an_empty_ledger_answers_with_an_empty_list_and_zero_totals(): void
    {
        $this->report()->assertStatus(200)->assertJson([
            'data' => [
                'metrics' => [
                    'customers' => [],
                    'customer_count' => 0,
                    'receivable' => '0.00',
                    'customer_credit' => '0.00',
                    'net_outstanding' => '0.00',
                    'period_movement' => '0.00',
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Current balance vs period movement — the central distinction
    // ------------------------------------------------------------------

    /**
     * The current balance is **not** narrowed by the date filter.
     *
     * The single most important property in this report, asserted directly: the
     * same request made with three wildly different windows must return exactly
     * the same balances and the same summary. A balance is a standing position —
     * everything the ledger has ever recorded — so a payment made last month
     * reduces the debt *today* whether or not it falls inside the window the user
     * happened to pick.
     *
     * @test
     */
    public function the_current_balance_is_independent_of_the_report_date_filter(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        // A debt raised long before any window anyone might ask for...
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', '2025-06-01 09:00:00');
        // ...and mostly paid off just before the reporting day.
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-400.00', '2026-01-14 18:00:00');

        $unfiltered = $this->report()->assertStatus(200);

        foreach ([
            ['date_from' => self::DAY, 'date_to' => self::DAY],
            ['date_from' => '2025-01-01', 'date_to' => '2025-12-31'],
            ['date_from' => '2030-01-01', 'date_to' => '2030-12-31'],
        ] as $window) {
            $windowed = $this->report($window)->assertStatus(200);

            // Only the *position* fields are compared. `period_movement` is
            // deliberately window-dependent — that is the whole point of having it
            // as a separate field — so comparing it here would be comparing the
            // wrong thing.
            $this->assertSame(
                $this->positionOf($unfiltered),
                $this->positionOf($windowed),
                'The listed balances must not move when the window does: '.json_encode($window)
            );

            foreach (['receivable', 'customer_credit', 'net_outstanding', 'customer_count'] as $metric) {
                $this->assertSame(
                    $unfiltered->json('data.metrics.'.$metric),
                    $windowed->json('data.metrics.'.$metric),
                    "metrics.{$metric} must not move when the window does."
                );
            }
        }

        // The balance is the whole history: 1,000 − 400, regardless of window.
        $this->assertSame('600.00', $unfiltered->json('data.metrics.customers.0.balance'));
    }

    /**
     * A window in the future moves nothing, because the balance is not a period.
     *
     * @test
     */
    public function a_window_containing_no_entries_still_reports_the_real_current_balance(): void
    {
        $this->receivableOn('Imran', '500.00', '2026-01-10');

        $this->report(['date_from' => '2030-01-01', 'date_to' => '2030-01-31'])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.customers.0.balance', '500.00')
            ->assertJsonPath('data.metrics.receivable', '500.00')
            // …while the *movement* in that empty window is genuinely nothing.
            ->assertJsonPath('data.metrics.period_movement', '0.00');
    }

    /**
     * `period_movement` obeys `date_from`.
     *
     * @test
     */
    public function period_movement_obeys_date_from(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', '2026-01-05 10:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '500.00', '2026-01-20 10:00:00');

        // Everything from the 15th onwards: the January entry is out.
        $this->report(['date_from' => '2026-01-15', 'date_to' => '2026-01-31'])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.period_movement', '500.00')
            // The balance, meanwhile, is the whole 1,500.
            ->assertJsonPath('data.metrics.customers.0.balance', '1500.00');
    }

    /**
     * `period_movement` obeys `date_to`.
     *
     * @test
     */
    public function period_movement_obeys_date_to(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', '2026-01-05 10:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '500.00', '2026-01-20 10:00:00');

        // Everything up to the 15th: the 20th entry is out.
        $this->report(['date_from' => '2026-01-01', 'date_to' => '2026-01-15'])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.period_movement', '1000.00')
            ->assertJsonPath('data.metrics.customers.0.balance', '1500.00');
    }

    /**
     * The `from` boundary is inclusive, to the second.
     *
     * @test
     */
    public function an_entry_exactly_on_the_from_boundary_is_inside_the_window(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        // One second before the window opens — out.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '100.00', '2026-01-14 23:59:59');
        // Exactly the first instant of the from day — in.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '200.00', '2026-01-15 00:00:00');

        $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.period_movement', '200.00');
    }

    /**
     * The `to` boundary covers the whole closing day, to the last instant.
     *
     * The half-open `< to + 1 day` form is what makes an afternoon entry on the
     * closing day count. A naive `<= 'Y-m-d'` would silently drop it, which is
     * the exact bug `ReportDateRange` exists to prevent.
     *
     * @test
     */
    public function the_to_boundary_includes_the_entire_closing_day(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        // The first instant of the to day.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '100.00', '2026-01-15 00:00:00');
        // Mid-afternoon — the case a `<= 'Y-m-d'` comparison would lose.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '200.00', '2026-01-15 14:30:00');
        // The last representable second of the to day.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '300.00', '2026-01-15 23:59:59');
        // The very next midnight — out.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '400.00', '2026-01-16 00:00:00');

        $response = $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])->assertStatus(200);

        $this->assertSame('600.00', $response->json('data.metrics.period_movement'));
        // The balance is still the whole 1,000.
        $this->assertSame('1000.00', $response->json('data.metrics.customers.0.balance'));
    }

    /**
     * Entries immediately outside **both** boundaries are excluded together.
     *
     * @test
     */
    public function entries_immediately_outside_both_boundaries_are_excluded(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '100.00', '2026-01-14 23:59:59');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '700.00', '2026-01-15 12:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '200.00', '2026-01-16 00:00:00');

        $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.period_movement', '700.00');
    }

    /**
     * A one-day window is a real day, not an empty one, and movement is signed.
     *
     * @test
     */
    public function a_single_day_window_captures_that_whole_day(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '250.00', '2026-01-14 18:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-100.00', '2026-01-15 09:15:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '400.00', '2026-01-16 11:00:00');

        $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])
            ->assertStatus(200)
            // A payment is money coming back, so movement keeps the ledger's sign.
            ->assertJsonPath('data.metrics.period_movement', '-100.00')
            ->assertJsonPath('data.metrics.customers.0.period_movement', '-100.00')
            ->assertJsonPath('data.metrics.customers.0.balance', '550.00');
    }

    /**
     * With no window at all the movement is the whole history.
     *
     * @test
     */
    public function an_unbounded_request_reports_whole_history_movement(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', '2024-01-01 08:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-250.00', '2026-01-15 08:00:00');

        $this->report()->assertStatus(200)
            ->assertJsonPath('data.period.from', null)
            ->assertJsonPath('data.period.to', null)
            // Movement and balance agree, because nothing was excluded.
            ->assertJsonPath('data.metrics.period_movement', '750.00')
            ->assertJsonPath('data.metrics.customers.0.balance', '750.00');
    }

    /**
     * A one-sided window is open on the other side.
     *
     * @test
     */
    public function a_one_sided_window_stays_open_on_the_other_side(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', '2026-01-05 10:00:00');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '500.00', '2026-01-25 10:00:00');

        // Only a lower bound.
        $this->report(['date_from' => '2026-01-15'])
            ->assertStatus(200)
            ->assertJsonPath('data.period.from', '2026-01-15')
            ->assertJsonPath('data.period.to', null)
            ->assertJsonPath('data.metrics.period_movement', '500.00');

        // Only an upper bound.
        $this->report(['date_to' => '2026-01-15'])
            ->assertStatus(200)
            ->assertJsonPath('data.period.from', null)
            ->assertJsonPath('data.period.to', '2026-01-15')
            ->assertJsonPath('data.metrics.period_movement', '1000.00');
    }

    /**
     * The window is echoed back, and a reversed one is still a 422.
     *
     * @test
     */
    public function the_period_is_echoed_and_a_reversed_window_is_rejected(): void
    {
        $this->receivableOn('Imran', '500.00');

        $this->report(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])
            ->assertStatus(200)
            ->assertJsonPath('data.period.from', '2026-01-01')
            ->assertJsonPath('data.period.to', '2026-01-31');

        $this->report(['date_from' => '2026-01-31', 'date_to' => '2026-01-01'])
            ->assertStatus(422);
    }

    /**
     * The balance sum carries no date predicate at all — proven in SQL.
     *
     * @test
     */
    public function the_balance_sum_carries_no_date_predicate_at_all(): void
    {
        $this->receivableOn('Imran', '500.00', '2026-01-10');

        // One warm-up request first. The Sanctum token and the account's roles /
        // permissions are resolved per request and would otherwise be counted as
        // part of the measured window, which is exactly what makes a raw query
        // count useless — every other report suite in this directory does the same.
        $this->report(['date_from' => '2030-01-01', 'date_to' => '2030-01-31'])->assertStatus(200);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->report(['date_from' => '2030-01-01', 'date_to' => '2030-01-31'])->assertStatus(200);

        // Two grouped aggregates are expected, and only two: the listing itself,
        // and the summary, which wraps that same grouping as a subquery so its
        // totals cover every customer rather than a page of them.
        $grouped = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                && str_contains(strtolower($entry['query']), 'customers.name')
        ));

        $queries = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_starts_with(strtoupper($entry['query']), 'SELECT')
        ));

        DB::disableQueryLog();

        $this->assertCount(2, $grouped, 'Exactly the listing and the summary aggregate.');
        // Three in total: the summary's own SELECT plus the two it is built from
        // being logged separately is *not* what happens — `fromSub` inlines the
        // grouping — so this is a hard ceiling proving there is no per-customer
        // query anywhere in the request.
        $this->assertLessThanOrEqual(3, count($queries), 'The request issues a constant number of queries.');

        foreach ($grouped as $index => $entry) {
            $sql = strtolower($entry['query']);

            // `balance` is the whole-history sum: no window may reach the column
            // that computes it. `period_movement` is the only windowed figure, and
            // it does its own bounding inside a CASE expression.
            $this->assertStringContainsString('sum(customer_ledger_entries.amount)', $sql);
            $this->assertStringNotContainsString('sum(customer_ledger_entries.amount) >=', $sql);
            $this->assertStringNotContainsString('where customer_ledger_entries.created_at', $sql);
            $this->assertStringContainsString('case when', $sql);
            $this->assertStringContainsString('customer_ledger_entries.created_at >=', $sql);
            $this->assertStringContainsString('customer_ledger_entries.created_at <', $sql);
        }
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /**
     * Another business's ledger is not merely excluded — it does not exist here.
     *
     * `customer_ledger_entries.business_id` is the primary tenant boundary and is
     * applied through `forBusiness()`. Two customers with the same name, one per
     * business, are the honest version of this test: a name-only match would look
     * correct and still be the wrong row.
     *
     * @test
     */
    public function another_businesss_ledger_is_excluded(): void
    {
        $theirs = Business::factory()->create();

        // Same name in both businesses — only the tenant tells them apart.
        $mine = $this->customer('Ayesha Rahman');
        $this->entry($mine, CustomerLedgerEntry::TYPE_SALE_DUE, '500.00', self::DAY);

        $theirsCustomer = Customer::factory()->create([
            'business_id' => $theirs->id,
            'name' => 'Ayesha Rahman',
        ]);
        $this->entry($theirsCustomer, CustomerLedgerEntry::TYPE_SALE_DUE, '9000.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertCount(1, $this->customers($response));
        $this->assertSame([$mine->id], $this->order($response));
        $this->assertSame('500.00', $response->json('data.metrics.customers.0.balance'));

        // The foreign 9,000 must not reach any total, either.
        $this->assertSame('500.00', $response->json('data.metrics.receivable'));
        $this->assertSame(1, $response->json('data.metrics.customer_count'));
    }

    /**
     * A **local** ledger entry pointing at a foreign `customer_id` cannot leak.
     *
     * This is the failure a `forBusiness()` scope alone does not prevent:
     * `customer_ledger_entries.customer_id` is a plain foreign key with no
     * composite constraint tying it to the entry's own tenant, so a corrupted or
     * hand-edited row is entirely possible. Scoping the ledger would group that
     * entry and print **the other business's customer id, name and balance**.
     *
     * The report must therefore guard the *joined customer* independently and drop
     * the row rather than attribute it to somebody. The whole payload is asserted,
     * because "the name is right but the balance is wrong" is still a leak.
     *
     * @test
     */
    public function a_local_entry_naming_a_foreign_customer_leaks_nothing(): void
    {
        $theirs = Business::factory()->create();
        $foreign = Customer::factory()->create([
            'business_id' => $theirs->id,
            'name' => 'Foreign Person',
        ]);

        // The tenant scope alone would happily group and print this row.
        CustomerLedgerEntry::create([
            'business_id' => $this->business->id,
            'customer_id' => $foreign->getKey(),
            'type' => CustomerLedgerEntry::TYPE_SALE_DUE,
            'amount' => '4242.00',
        ]);

        // A genuine local receivable, so the report is not empty and the absence
        // below cannot be mistaken for "there was nothing to show".
        $mine = $this->receivableOn('Imran', '500.00');

        $response = $this->report()->assertStatus(200);

        $this->assertSame([$mine->id], $this->order($response));

        // Not the name, and not the balance — anywhere in the payload.
        $body = (string) json_encode($response->json('data'));

        $this->assertStringNotContainsString('Foreign Person', $body);
        $this->assertStringNotContainsString('4242.00', $body);

        // And structurally: the foreign customer is not among the rows at all, so
        // there is no id, no name and no figure attributable to them. Asserted as
        // membership rather than as a substring, because a bare id appears
        // incidentally inside unrelated numbers.
        $this->assertNotContains(
            $foreign->getKey(),
            $this->order($response),
            'The foreign customer must not appear in the listing.'
        );

        $this->assertSame('500.00', $response->json('data.metrics.receivable'));
    }

    /**
     * A valid local customer appears, with their own figure intact.
     *
     * The counterpart to the two isolation tests above: guarding the join must not
     * have quietly dropped legitimate rows along with the corrupted one.
     *
     * @test
     */
    public function a_valid_local_customer_appears_with_their_own_figure(): void
    {
        $customer = $this->receivableOn('Rafiq', '1900.00');

        $row = $this->rowFor($this->report()->assertStatus(200), $customer);

        $this->assertSame('Rafiq', $row['customer_name'] ?? null);
        $this->assertSame('1900.00', $row['balance'] ?? null);
        $this->assertSame('receivable', $row['status'] ?? null);
    }

    /**
     * A soft-deleted customer is not outstanding any more.
     *
     * `Customer` soft-deletes and every other customer-facing report agrees with
     * the customer screen about whether a removed customer still exists. Their
     * ledger rows stay — history is immutable — but the join's `deleted_at IS NULL`
     * is what stops the report contradicting the customer list.
     *
     * @test
     */
    public function a_soft_deleted_customer_is_not_listed(): void
    {
        $customer = $this->receivableOn('Imran', '500.00');
        $this->receivableOn('Nusrat', '625.00');

        $customer->delete();

        $response = $this->report()->assertStatus(200);

        $this->assertCount(1, $this->customers($response));
        $this->assertSame('625.00', $response->json('data.metrics.receivable'));
    }

    // ------------------------------------------------------------------
    // The customer filter
    // ------------------------------------------------------------------

    /**
     * `customer_id` narrows both the listing and the summary.
     *
     * The summary is aggregated from the same filtered builder, so the totals
     * describe the filtered set rather than the whole book behind a filtered list.
     *
     * @test
     */
    public function the_customer_filter_narrows_the_listing_and_the_summary(): void
    {
        $imran = $this->receivableOn('Imran', '500.00');
        $this->receivableOn('Nusrat', '625.00');
        $this->receivableOn('Rafiq', '1900.00');

        $response = $this->report(['customer_id' => $imran->id])->assertStatus(200);

        $this->assertSame([$imran->id], $this->order($response));
        $this->assertSame('500.00', $response->json('data.metrics.customers.0.balance'));
        $this->assertSame(1, $response->json('data.metrics.customer_count'));
        $this->assertSame('500.00', $response->json('data.metrics.receivable'));
    }

    /**
     * The filter also selects a credit balance, without flipping its sign.
     *
     * @test
     */
    public function the_customer_filter_can_select_a_credit_balance(): void
    {
        $credit = $this->customer('Nusrat');
        $this->entry($credit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-750.00', self::DAY);
        $this->receivableOn('Rafiq', '1900.00');

        $response = $this->report(['customer_id' => $credit->id])->assertStatus(200);

        $row = $this->rowFor($response, $credit);

        $this->assertSame('-750.00', $row['balance'] ?? null);
        $this->assertSame('customer_credit', $row['status'] ?? null);
        $this->assertSame('0.00', $response->json('data.metrics.receivable'));
        $this->assertSame('750.00', $response->json('data.metrics.customer_credit'));
    }

    /**
     * A foreign `customer_id` cannot be used as a tenant probe.
     *
     * `ReportFilterRequest` rejects the id at validation against
     * `Rule::exists('customers', 'id')->where('business_id', …)`, so this is a 422
     * on the established rule — not a new authorization path, and not an empty 200
     * that would confirm the customer exists somewhere.
     *
     * @test
     */
    public function a_cross_business_customer_filter_is_rejected(): void
    {
        $theirs = Business::factory()->create();
        $foreign = Customer::factory()->create([
            'business_id' => $theirs->id,
            'name' => 'Foreign Person',
        ]);

        $this->receivableOn('Imran', '500.00');

        $response = $this->report(['customer_id' => $foreign->getKey()])->assertStatus(422);

        $response->assertJsonValidationErrors('customer_id');

        // And nothing leaked on the way out.
        $body = (string) json_encode($response->json());
        $this->assertStringNotContainsString('Imran', $body);
        $this->assertStringNotContainsString('Foreign Person', $body);
    }

    /**
     * A `customer_id` that exists nowhere at all is rejected the same way.
     *
     * @test
     */
    public function an_unknown_customer_filter_is_rejected(): void
    {
        $this->receivableOn('Imran', '500.00');

        $this->report(['customer_id' => 99999999])->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    // ------------------------------------------------------------------
    // Ordering
    // ------------------------------------------------------------------

    /**
     * Receivables first and largest first, then credits largest first.
     *
     * Money the business is owed must never be buried under money it owes, and
     * within each direction the biggest figure leads — which is the order a
     * collections list is actually worked down.
     *
     * @test
     */
    public function receivables_come_before_credits_and_largest_lead(): void
    {
        $small = $this->receivableOn('Small', '500.00');
        $big = $this->receivableOn('Big', '1900.00');
        $mid = $this->receivableOn('Mid', '625.00');

        $minorCredit = $this->customer('Minor Credit');
        $this->entry($minorCredit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-125.00', self::DAY);

        $majorCredit = $this->customer('Major Credit');
        $this->entry($majorCredit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-750.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertSame(
            [$big->id, $mid->id, $small->id, $majorCredit->id, $minorCredit->id],
            $this->order($response)
        );
    }

    /**
     * Two customers on the same figure are ordered by id, so the list never reshuffles.
     *
     * Without the tie-break the order would be whatever the database happened to
     * return, and two identical requests could come back in two different orders —
     * which makes the same business look like it changed between refreshes.
     *
     * @test
     */
    public function equal_balances_are_ordered_by_customer_id(): void
    {
        $first = $this->receivableOn('First', '500.00');
        $second = $this->receivableOn('Second', '500.00');
        $third = $this->receivableOn('Third', '500.00');

        $expected = [$first->id, $second->id, $third->id];
        sort($expected);

        $response = $this->report()->assertStatus(200);

        $this->assertSame($expected, $this->order($response));

        // Repeated identically, so the assertion is about a fixed order rather than
        // about one lucky response.
        $this->assertSame($expected, $this->order($this->report()->assertStatus(200)));
    }

    // ------------------------------------------------------------------
    // Shape, contract and query cost
    // ------------------------------------------------------------------

    /**
     * The response contract is exactly what it promises, key for key.
     *
     * A JSON payload has no types, so the *shape* is pinned here and the wire types
     * in the next test: an integer id, money as a two-decimal **string**, and a
     * named status rather than a sign the client has to interpret for itself.
     *
     * @test
     */
    public function the_response_carries_exactly_the_promised_contract(): void
    {
        $this->receivableOn('Imran', '500.00');

        $response = $this->report()->assertStatus(200);

        $this->assertSame(['from', 'to'], array_keys($response->json('data.period')));

        $metrics = $response->json('data.metrics');

        foreach (['customer_count', 'receivable', 'customer_credit', 'net_outstanding', 'period_movement', 'customers'] as $key) {
            $this->assertArrayHasKey($key, $metrics);
        }

        $this->assertSame(
            ['customer_id', 'customer_name', 'balance', 'status', 'period_movement'],
            array_keys($response->json('data.metrics.customers.0'))
        );

        // No `due_amount` anywhere: this report is the ledger's, not the invoice's.
        $this->assertStringNotContainsString('due_amount', (string) json_encode($response->json('data')));
    }

    /**
     * Every money field is a formatted string, and every count an integer.
     *
     * @test
     */
    public function money_arrives_as_a_two_decimal_string_and_counts_as_integers(): void
    {
        $customer = $this->receivableOn('Ayesha Rahman', '500.00');

        $response = $this->report()->assertStatus(200);
        $row = $response->json('data.metrics.customers.0');

        foreach (['balance', 'period_movement'] as $key) {
            $this->assertIsString($row[$key]);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $row[$key]);
        }

        foreach (['receivable', 'customer_credit', 'net_outstanding', 'period_movement'] as $key) {
            $this->assertIsString($response->json('data.metrics.'.$key));
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $response->json('data.metrics.'.$key));
        }

        $this->assertSame($customer->id, $row['customer_id']);
        $this->assertIsInt($response->json('data.metrics.customer_count'));
    }

    /**
     * The listing is not capped: it is a receivables statement, not a leaderboard.
     *
     * The sales leaderboards cut to a top 10 because a ranking answers "who are my
     * biggest"; this list answers "who owes me", and silently dropping customers
     * from a debt register would understate the very figure printed above it. Twenty
     * receivables therefore come back as twenty rows, each with its own balance.
     *
     * @test
     */
    public function the_listing_is_not_truncated_to_a_top_n(): void
    {
        $expected = Money::format(null);

        for ($index = 1; $index <= 20; $index++) {
            $amount = (string) (100 * $index).'.00';
            $this->receivableOn('Customer '.$index, $amount);
            $expected = Money::add($expected, $amount);
        }

        $response = $this->report()->assertStatus(200);

        $this->assertCount(20, $this->customers($response));
        $this->assertSame(20, $response->json('data.metrics.customer_count'));

        // …and the summary describes all twenty, matching the listing exactly.
        $this->assertSame($expected, $response->json('data.metrics.receivable'));
    }

    /**
     * The summary is computed from the same population the listing is.
     *
     * The listing is not bounded, so the two are the same set today — this test
     * pins that they cannot drift, which is what would let a total and the table
     * beneath it disagree.
     *
     * @test
     */
    public function the_summary_covers_every_listed_customer(): void
    {
        $this->receivableOn('Imran', '500.00');
        $this->receivableOn('Nusrat', '625.00');
        $this->receivableOn('Rafiq', '1900.00');

        $credit = $this->customer('Farhana');
        $this->entry($credit, CustomerLedgerEntry::TYPE_SALE_RETURN, '-125.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        // Recomputed from the returned rows with the project's own money helper, so
        // the check does not restate the arithmetic in float.
        $receivable = Money::format(null);
        $customerCredit = Money::format(null);

        foreach ($this->customers($response) as $row) {
            if (Money::isNegative($row['balance'])) {
                $customerCredit = Money::add($customerCredit, Money::subtract('0.00', $row['balance']));
            } else {
                $receivable = Money::add($receivable, $row['balance']);
            }
        }

        $this->assertSame('3025.00', $receivable);
        $this->assertSame('125.00', $customerCredit);
        $this->assertSame($receivable, $response->json('data.metrics.receivable'));
        $this->assertSame($customerCredit, $response->json('data.metrics.customer_credit'));
        $this->assertSame(count($this->customers($response)), $response->json('data.metrics.customer_count'));
    }

    // ------------------------------------------------------------------
    // Query cost and SQL shape
    // ------------------------------------------------------------------

    /**
     * The cost is a constant number of queries, whatever the customer count.
     *
     * This is the N+1 guard proper: the request is warmed up first so the Sanctum
     * token and the permission checks are not counted, then the window is held
     * fixed while the number of distinct customers varies. A per-customer balance
     * query would make the second count strictly larger than the first.
     *
     * @test
     */
    public function the_query_count_does_not_grow_with_the_number_of_customers(): void
    {
        $this->receivableOn('First', '100.00');
        $this->receivableOn('Second', '200.00');

        // Warm-up: the token and the role / permission lookups resolve per request.
        $this->report()->assertStatus(200);

        $twoCustomers = $this->countQueries(fn () => $this->report()->assertStatus(200));

        for ($index = 3; $index <= 30; $index++) {
            $this->receivableOn('Customer '.$index, '50.00');
        }

        $thirtyCustomers = $this->countQueries(fn () => $this->report()->assertStatus(200));

        $this->assertSame(
            $twoCustomers,
            $thirtyCustomers,
            'query count must not grow with the number of customers'
        );

        // A ceiling as well: it catches a per-customer implementation outright, and
        // stays honest if another aggregate is ever added to the report.
        $this->assertLessThanOrEqual(8, $thirtyCustomers);
    }

    /**
     * The listing is one grouped aggregate, and the summary wraps that same grouping.
     *
     * Proven by inspecting the SQL rather than by counting rows: an implementation
     * that loaded every ledger entry and summed it in PHP would return identical
     * numbers at an identical query count, and still scale with the size of the
     * ledger rather than its length.
     *
     * @test
     */
    public function the_listing_is_a_sql_aggregation_over_the_ledger(): void
    {
        $this->receivableOn('Imran', '500.00');

        $this->report()->assertStatus(200);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->report()->assertStatus(200);

            $grouped = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains(strtolower($entry['query']), 'group by')
                    && str_contains(strtolower($entry['query']), 'customers.name'),
            ));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(2, $grouped, 'Exactly the listing and the summary aggregate.');

        // The summary wraps the listing as a subquery, so it contains every clause
        // the listing has *plus* the outer roll-up. The two are separated by which
        // one selects the aggregates, so each is asserted against its own SQL
        // rather than against an arbitrary first entry.
        $isSummary = fn (string $sql): bool => str_contains($sql, 'as customer_count');

        $summarySql = null;
        $listingSql = null;

        foreach ($grouped as $entry) {
            // Identifier quoting is driver-specific, so the clauses are compared
            // with it stripped — it is the clauses that matter, not how either
            // driver spells them.
            $sql = strtolower(str_replace(['"', '`'], '', $entry['query']));

            if ($isSummary($sql)) {
                $summarySql = $sql;
            } else {
                $listingSql = $sql;
            }
        }

        $this->assertNotNull($listingSql, 'The per-customer listing aggregate.');
        $this->assertNotNull($summarySql, 'The summary aggregate.');

        // Both guards, stated separately: the ledger's own scope *and* the joined
        // customer pinned to the same business. Dropping either one is exactly
        // what the isolation tests above would catch.
        $this->assertStringContainsString('customer_ledger_entries.business_id = ?', $listingSql);
        $this->assertStringContainsString('customers.business_id = ?', $listingSql);
        $this->assertStringContainsString('customers.deleted_at is null', $listingSql);

        // The sum is the ledger's, grouped, in the database.
        $this->assertStringContainsString('sum(customer_ledger_entries.amount)', $listingSql);
        $this->assertStringContainsString('group by customers.id, customers.name', $listingSql);

        // The whole balance is not bounded; only the movement is.
        // The `HAVING` repeats the aggregate rather than naming its alias, because
        // MySQL refuses a group-function alias inside another expression — SQLite
        // accepts it, so this is the assertion that keeps the query portable to
        // the project's real driver.
        $this->assertStringContainsString(
            'having coalesce(sum(customer_ledger_entries.amount), 0) != 0',
            (string) $listingSql
        );
        $this->assertStringNotContainsString('having balance', (string) $listingSql);
        $this->assertStringNotContainsString('where customer_ledger_entries.created_at', (string) $listingSql);

        // The invoice column, and the sales table, are nowhere in the statement.
        $this->assertStringNotContainsString('due_amount', (string) $listingSql);
        $this->assertStringNotContainsString('sales', (string) $listingSql);

        // Receipts before credits, largest first in each direction, id as the
        // tie-break. `ABS()` of the aggregate is what orders the credits by size
        // rather than by the raw (negative) number — and, again, is the aggregate
        // itself rather than the `balance` alias for the same MySQL reason.
        $this->assertStringContainsString(
            'order by case when coalesce(sum(customer_ledger_entries.amount), 0) < 0 then 1 else 0 end',
            (string) $listingSql
        );
        $this->assertStringContainsString(
            'abs(coalesce(sum(customer_ledger_entries.amount), 0)) desc',
            (string) $listingSql
        );
        $this->assertStringContainsString('customers.id asc', (string) $listingSql);

        // No leaderboard cut: this listing is the whole register, not a top N.
        $this->assertStringNotContainsString('limit', (string) $listingSql);

        // The summary is a roll-up of that same grouping rather than a second,
        // independently filtered read — which is what makes its totals cover every
        // qualifying customer rather than a page of them.
        $this->assertStringContainsString('sum(case when balance > 0 then balance else 0 end)', (string) $summarySql);
        $this->assertStringContainsString('sum(case when balance < 0 then -balance else 0 end)', (string) $summarySql);
        $this->assertStringContainsString('outstanding_balances', (string) $summarySql);
    }

    // ------------------------------------------------------------------
    // Walk-in customers, and what reporting did not introduce
    // ------------------------------------------------------------------

    /**
     * There is no walk-in bucket, because the schema cannot hold one.
     *
     * `customer_ledger_entries.customer_id` is a **non-nullable** foreign key and
     * every write path resolves the customer through `forBusiness()`. A sale with
     * no customer therefore writes no ledger row at all, so there is nothing to
     * report and no reserved row to invent. Proved by driving a real walk-in sale
     * through the HTTP flow and asserting the ledger stays empty — inventing a
     * "Walk-in Customer" line here would put a fake customer and a fake balance
     * into a financial listing.
     *
     * @test
     */
    public function a_walk_in_sale_creates_no_ledger_row_and_no_placeholder(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => '40.00',
            'selling_price' => '100.00',
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '50',
        ]);

        // A counter sale, paid in full, naming no customer at all. `sale_date` is
        // required and the line total is server-computed, so neither is sent.
        $this->postJson('/api/v1/sales', [
            'sale_date' => self::DAY,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => '100.00',
            ]],
            'payment_method' => 'cash',
            'paid_amount' => '100.00',
        ])->assertStatus(201);

        $this->assertSame(0, CustomerLedgerEntry::query()->count());

        // So the report is empty — not one row dressed up as a walk-in customer.
        $this->report()->assertStatus(200)
            ->assertJsonPath('data.metrics.customers', [])
            ->assertJsonPath('data.metrics.customer_count', 0)
            ->assertJsonPath('data.metrics.receivable', '0.00');
    }

    /**
     * No report-shaped table was added to answer this report.
     *
     * The balance is a sum over an existing ledger; a cache or snapshot table would
     * be a second source of truth that could disagree with the ledger it came from.
     *
     * @test
     */
    public function no_report_shaped_table_was_added_for_this_report(): void
    {
        $this->assertTrue(Schema::hasTable('customer_ledger_entries'));

        foreach ([
            'report_customer_outstandings',
            'report_receivables',
            'customer_outstanding_snapshots',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist");
        }
    }

    /**
     * Reporting does not change what the ledger itself means.
     *
     * The report is read-only, so writing and reading must not perturb each other:
     * the same movements produce the same figures, and the ledger is exactly what
     * the writes created — nothing cached, denormalised or double-written by having
     * produced a report in between.
     *
     * @test
     */
    public function producing_the_report_leaves_the_ledger_untouched(): void
    {
        $customer = $this->customer('Ayesha Rahman');
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '1000.00', self::DAY);
        $this->entry($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, '-250.00', self::DAY);

        $before = $this->ledgerState();
        $this->assertCount(2, $before);

        // Several requests, including a filtered one and a windowed one.
        $this->report()->assertStatus(200);
        $this->report(['customer_id' => $customer->id])->assertStatus(200);
        $this->report(['date_from' => self::DAY, 'date_to' => self::DAY])->assertStatus(200);

        $this->assertSame($before, $this->ledgerState(), 'Reading the report must not write to the ledger.');
        $this->assertSame('750.00', $this->report()->assertStatus(200)->json('data.metrics.receivable'));
    }

    /**
     * `sales.due_amount` is never consulted, and the invoice is left alone.
     *
     * The invoice column is maintained in one direction only — a later payment
     * revises it, a later return never does — so summing it would report money the
     * business already has. Asserted against the arithmetic *and* against the
     * stored column, so "the report agrees with the ledger" cannot be achieved by
     * quietly rewriting the invoice.
     *
     * @test
     */
    public function the_invoice_due_column_is_never_consulted_or_rewritten(): void
    {
        $customer = $this->customer('Ayesha Rahman');

        // An invoice that says 1,000 is still due...
        $sale = $this->invoice($customer, '1000.00');
        $this->assertSame('1000.00', Money::format($sale->due_amount));

        // …against a ledger that says 250.
        $this->entry($customer, CustomerLedgerEntry::TYPE_SALE_DUE, '250.00', self::DAY);

        $response = $this->report()->assertStatus(200);

        $this->assertSame('250.00', $response->json('data.metrics.customers.0.balance'));
        $this->assertSame('250.00', $response->json('data.metrics.receivable'));

        $sale->refresh();
        $this->assertSame('1000.00', Money::format($sale->due_amount));
    }
}
