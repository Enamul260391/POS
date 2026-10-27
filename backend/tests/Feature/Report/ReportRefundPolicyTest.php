<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Customer\CustomerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — the **refund, cash and receivable** policy.
 *
 * A refund is the one event that touches every money figure at once, and each
 * figure it touches is wrong in a different direction:
 *
 *   - as a **collection** it inflates revenue for a sale that never happened;
 *   - as **dropped** it hides money that left the till, so the drawer and the
 *     books disagree and nobody can reconcile either;
 *   - as a **receivable** it makes the shop look owed money it has already had
 *     back.
 *
 * So each of those is asserted separately, on data produced by the real HTTP
 * flows rather than hand-written fixtures.
 *
 * No cash-register *report* is built here. What is verified is that the data a
 * future one would need already holds: cash moves the drawer, card and bank do
 * not, and an automatic movement is never editable as a manual one.
 */
class ReportRefundPolicyTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private const DAY = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
    }

    /** A stock-keeping product with 100 units on hand. */
    private function product(string $cost = '60.00', string $price = '100.00'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '100',
            'created_by' => $this->user->id,
        ]);

        return $product;
    }

    /** A customer whose account was opened through the service. */
    private function customer(string $openingBalance = '0'): Customer
    {
        return $this->app->make(CustomerService::class)->create($this->business->id, [
            'name' => 'Refund Customer',
            'opening_balance' => $openingBalance,
        ]);
    }

    /**
     * Record a sale through the API.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function sale(array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => self::DAY,
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);
        $sale = $response->json('data');

        return [$sale, array_map('intval', array_column($sale['items'], 'id'))];
    }

    /** A sale of `$quantity` units at ৳100, paid with one method. */
    private function salePaidWith(Product $product, string $paidAmount, string $method, ?Customer $customer = null): array
    {
        return $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            [
                'paid_amount' => $paidAmount,
                'payment_method' => $method,
                'customer_id' => $customer?->id,
            ],
        );
    }

    /**
     * Return part of an invoice, settled through the given methods.
     *
     * The return date is pinned to the reporting day rather than left to the
     * service's "today" default, which would place every return outside the
     * window under test and make the assertions vacuous.
     *
     * @param  array<int, array<string, mixed>>  $refunds
     */
    private function returnLine(int $saleId, int $saleItemId, string $quantity, array $refunds = []): void
    {
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $saleId,
            'return_date' => self::DAY,
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => $quantity]],
            'refunds' => $refunds,
        ])->assertStatus(201);
    }

    /** Open this user's drawer, so automatic cash movements have somewhere to go. */
    private function openRegister(): CashRegister
    {
        $response = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 0]);
        $response->assertStatus(201);

        return CashRegister::findOrFail($response->json('data.id'));
    }

    /** `GET /reports/sales` for the single reporting day. */
    private function salesReport(string $query = ''): TestResponse
    {
        return $this->getJson(
            '/api/v1/reports/sales?date_from='.self::DAY.'&date_to='.self::DAY
            .($query === '' ? '' : '&'.$query),
        );
    }
    // ------------------------------------------------------------------
    // A refund is outbound, and must never read as a collection
    // ------------------------------------------------------------------

    /**
     * Four figures, four ways a refund breaks them.
     *
     * The sale is ৳1,000 with ৳600 collected, so ৳400 is on the customer's
     * account. A ৳300 return comes back in cash and settles ৳100 on the account.
     * Nothing about that may add a sale, add revenue, add a collection or add to
     * what is owed.
     *
     * @test
     */
    public function a_refund_does_not_increase_sales_revenue_collection_or_the_receivable(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        [$sale, $items] = $this->salePaidWith(
            $product,
            '600',
            Payment::PAYMENT_METHOD_CASH,
            $customer,
        );

        $before = $this->salesReport()->assertStatus(200);
        $this->assertSame('400.00', $before->json('data.metrics.due_amount'));
        $this->assertSame('400.00', $before->json('data.metrics.customer_outstanding'));

        $this->returnLine($sale['id'], $items[0], '3', [
            ['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => 300],
        ]);

        $after = $this->salesReport()->assertStatus(200);

        // No new sale, and no more revenue than the invoice was ever worth.
        $this->assertSame(1, $after->json('data.metrics.sale_count'));
        $this->assertSame('1000.00', $after->json('data.metrics.total_sales'));

        // The collection is ৳600 received — not ৳900, and not ৳600 + ৳300.
        $this->assertSame('600.00', $after->json('data.metrics.paid_amount'));
        $this->assertSame(
            [['cash', 1, '600.00']],
            array_map(
                static fn (array $row): array => [$row['payment_method'], $row['payment_count'], $row['total_amount']],
                $after->json('data.metrics.payment_breakdown'),
            ),
            'A refund must never appear as a second collection of the same method.',
        );

        // The receivable is unchanged: the whole ৳300 return was refunded out of
        // the ৳600 already collected, so only the ledger's *unsettled* remainder
        // would ever touch the account — and that was ৳0. `due_amount` likewise
        // stays the invoice's own frozen statement of what it was worth.
        $this->assertSame('400.00', $after->json('data.metrics.customer_outstanding'));
        $this->assertSame('400.00', $after->json('data.metrics.due_amount'));
    }

    /**
     * The outbound half is reported, not merely excluded.
     *
     * A refund that is only ever *not counted* is a refund nobody can reconcile:
     * the drawer moves money and no report mentions it. It therefore gets its own
     * total, its own per-method split and a stated net collection.
     *
     * The invoice is collected half in cash and half by card so the return has two
     * methods to settle through — one settlement per method is what makes the
     * per-method split meaningful rather than a single arbitrary row.
     *
     * @test
     */
    public function a_refund_stays_separately_identifiable_as_outbound_settlement(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->salePaidWith($product, '600', Payment::PAYMENT_METHOD_CASH);

        $this->postJson('/api/v1/sales/'.$sale['id'].'/payments', [
            'payment_method' => Payment::PAYMENT_METHOD_CARD,
            'amount' => 400,
        ])->assertStatus(201);

        $this->returnLine($sale['id'], $items[0], '3', [
            ['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => 200],
            ['payment_method' => Payment::PAYMENT_METHOD_CARD, 'amount' => 100],
        ]);

        $report = $this->salesReport()->assertStatus(200);

        $this->assertSame(2, $report->json('data.metrics.refund_count'));
        $this->assertSame('300.00', $report->json('data.metrics.refund_amount'));
        $this->assertSame(
            [['cash', '200.00'], ['card', '100.00']],
            array_map(
                static fn (array $row): array => [$row['payment_method'], $row['refund_amount']],
                $report->json('data.metrics.refund_breakdown'),
            ),
            'A mixed refund is split by method and ordered largest-first, like the inbound split.',
        );
        $this->assertSame(
            '700.00',
            $report->json('data.metrics.net_collected_amount'),
            'Net collection is what came in (৳1,000) less what went back out (৳300).',
        );
    }

    /**
     * Only the **unsettled** part of a return reaches the customer's account.
     *
     * A ৳1,000 invoice with ৳200 collected leaves ৳800 owing. Returning ৳300 of
     * goods refunds ৳200 in cash — there is no more cash to hand back — and
     * settles the remaining ৳100 on the account, taking it to ৳700. Recording the
     * whole ৳300 would be recording money the business returned twice.
     *
     * @test
     */
    public function only_the_unsettled_portion_of_a_return_reduces_the_receivable(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        [$sale, $items] = $this->salePaidWith(
            $product,
            '200',
            Payment::PAYMENT_METHOD_CASH,
            $customer,
        );

        $this->returnLine($sale['id'], $items[0], '3');

        $report = $this->salesReport()->assertStatus(200);

        $this->assertSame('300.00', $report->json('data.metrics.returns_amount'));
        // Capped at what was actually collected: ৳200, not ৳300.
        $this->assertSame('200.00', $report->json('data.metrics.refund_amount'));
        $this->assertSame('700.00', $report->json('data.metrics.customer_outstanding'));
        $this->assertSame(
            [['cash', 1, '200.00']],
            array_map(
                static fn (array $row): array => [$row['payment_method'], $row['refund_count'], $row['refund_amount']],
                $report->json('data.metrics.refund_breakdown'),
            ),
        );
    }
    // ------------------------------------------------------------------
    // Cash
    // ------------------------------------------------------------------

    /**
     * Only the **cash** portion of a refund moves the drawer.
     *
     * A card refund is money leaving the merchant's account, not the till, so
     * posting a drawer movement for it would make the reported cash balance
     * disagree with the cash actually held — the failure that sends a cashier
     * hunting for money that was never in the drawer.
     *
     * @test
     */
    public function only_the_cash_portion_of_a_refund_moves_the_cash_register(): void
    {
        $product = $this->product();

        // A card invoice: the drawer never saw this money, so it must not give
        // it back.
        [$cardSale, $cardItems] = $this->salePaidWith($product, '500', Payment::PAYMENT_METHOD_CARD);
        $register = $this->openRegister();
        $this->returnLine($cardSale['id'], $cardItems[0], '2');

        $this->assertSame(
            0,
            CashRegisterTransaction::query()->where('type', CashRegisterTransaction::TYPE_REFUND)->count(),
            'A card refund must not post a cash-register movement.',
        );

        // A cash invoice: the drawer takes ৳200 back out.
        [$cashSale, $cashItems] = $this->salePaidWith($product, '500', Payment::PAYMENT_METHOD_CASH);
        $this->returnLine($cashSale['id'], $cashItems[0], '2');

        $movements = CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_REFUND)
            ->get();

        $this->assertCount(1, $movements, 'The cash refund posts exactly one drawer movement.');
        $this->assertSame('200.00', $movements->first()->amount);
        $this->assertSame(SaleReturn::class, $movements->first()->reference_type);
    }

    /**
     * An automatic movement is never a manual one.
     *
     * The manual cash-in / cash-out endpoints refuse any row carrying a
     * `reference_*`, which is what stops a refund movement being edited or
     * deleted as though a human had typed it into the drawer.
     *
     * @test
     */
    public function an_automatic_refund_movement_is_never_editable_as_a_manual_cash_movement(): void
    {
        $product = $this->product();
        [$sale, $items] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CASH);

        $register = $this->openRegister();
        $this->returnLine($sale['id'], $items[0], '2');

        $movement = CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_REFUND)
            ->firstOrFail();

        $this->assertNotContains($movement->type, CashRegisterTransaction::MANUAL_TYPES);
        $this->assertSame(SaleReturn::class, $movement->reference_type);
        $this->assertNotNull($movement->reference_id);

        // The manual endpoints refuse to touch it, in either direction — with the
        // project's existing 409 business-rule refusal, not a new status.
        $this->putJson(
            '/api/v1/cash-registers/'.$register->id.'/transactions/'.$movement->id,
            ['amount' => 1],
        )->assertStatus(409)->assertJson([
            'success' => false,
            'message' => CashRegisterService::NOT_MANUAL_MESSAGE,
        ]);

        $this->deleteJson(
            '/api/v1/cash-registers/'.$register->id.'/transactions/'.$movement->id,
        )->assertStatus(409);

        // The movement is untouched.
        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $movement->id,
            'amount' => '200.00',
        ]);
    }
    // ------------------------------------------------------------------
    // Receivables
    // ------------------------------------------------------------------

    /**
     * The outstanding figure is the ledger's, never the invoice's.
     *
     * `sales.due_amount` is revised when its invoice is paid and is left alone
     * when goods come back — so after a return the two disagree, and only one of
     * them is telling the truth. A ৳1,000 invoice with ৳200 collected leaves
     * ৳800 on the invoice; returning ৳300 of goods settles ৳100 of it, so what
     * the customer actually owes is ৳700. The column still says ৳800, and the
     * report must not repeat it.
     *
     * @test
     */
    public function customer_outstanding_comes_from_the_ledger_not_from_the_historical_due_amount(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        [$sale, $items] = $this->salePaidWith(
            $product,
            '200',
            Payment::PAYMENT_METHOD_CASH,
            $customer,
        );

        $open = $this->salesReport()->assertStatus(200);
        $this->assertSame('800.00', $open->json('data.metrics.due_amount'));
        $this->assertSame('800.00', $open->json('data.metrics.customer_outstanding'));

        // ৳200 comes back in cash; the remaining ৳100 settles on the account.
        $this->returnLine($sale['id'], $items[0], '3');

        $after = $this->salesReport()->assertStatus(200);

        $this->assertSame(
            '700.00',
            $after->json('data.metrics.customer_outstanding'),
            'The ledger knows ৳100 of the return settled the account.',
        );
        $this->assertSame(
            '800.00',
            $after->json('data.metrics.due_amount'),
            'The invoice still says ৳800, because a return never rewrites it — which is exactly why it is not the balance.',
        );

        // And the ledger agrees with the report, independently of it.
        $this->assertSame(
            '700.00',
            Money::format(
                CustomerLedgerEntry::query()
                    ->forBusiness($this->business->id)
                    ->forCustomer($customer->id)
                    ->sum('amount'),
            ),
        );
    }

    /**
     * A settled invoice reads the same either way, so the divergence above is
     * the *return* that proves the point rather than an artefact.
     *
     * @test
     */
    public function a_fully_paid_invoice_agrees_with_the_ledger_and_stays_agreed(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        [$sale] = $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['customer_id' => $customer->id],
        );

        $this->postJson('/api/v1/sales/'.$sale['id'].'/payments', [
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
            'amount' => 1000,
        ])->assertStatus(201);

        $report = $this->salesReport()->assertStatus(200);

        $this->assertSame('0.00', $report->json('data.metrics.customer_outstanding'));
        $this->assertSame('0.00', $report->json('data.metrics.due_amount'));
    }

    /**
     * A `customer_id` filter narrows the receivable to that customer.
     *
     * @test
     */
    public function a_customer_filter_narrows_the_receivable_to_that_customer(): void
    {
        $product = $this->product();
        $owing = $this->customer();
        $other = $this->customer();

        // The customer is attached at creation, because that is when the sale
        // writes its `sale_due` ledger entry — attaching one afterwards would
        // leave an invoice with no account movement behind it.
        $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['customer_id' => $owing->id],
        );
        $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['customer_id' => $other->id],
        );

        // Unfiltered: the whole business owes ৳2,000.
        $this->salesReport()
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.customer_outstanding', '2000.00');

        // Scoped: one customer at a time.
        $this->salesReport('customer_id='.$owing->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.customer_outstanding', '1000.00');

        $this->salesReport('customer_id='.$other->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.customer_outstanding', '1000.00');
    }

    /**
     * An opening advance is carried into the balance the report reads.
     *
     * The ledger's convention is "positive = the customer owes us", so a customer
     * opened with a ৳250 advance and then given ৳1,000 of goods on credit owes
     * ৳1,250. The opening balance reaches the report only because the report
     * reads the ledger rather than summing `sales.due_amount`.
     *
     * @test
     */
    public function an_opening_advance_is_carried_by_the_ledger_and_reported_as_owed(): void
    {
        $product = $this->product();
        $customer = $this->customer(openingBalance: '250');

        $this->sale(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
            ['customer_id' => $customer->id],
        );

        $this->salesReport('customer_id='.$customer->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.customer_outstanding', '1250.00');
    }
}
