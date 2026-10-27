<?php

declare(strict_types=1);

namespace Tests\Feature\SaleReturn;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\InventoryBatch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Customer\CustomerLedgerService;
use App\Services\Customer\CustomerService;
use App\Support\Money;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * The settlement half of a return: refund payments, the cash drawer, and the
 * customer's account.
 *
 * Three things must hold at once for a return to be financially correct, and
 * each fails in a different way, so this suite is grouped by the guarantee
 * rather than by endpoint:
 *
 *  - **Refund payments.** A return pays money back, and that money must be
 *    recorded as leaving the business — for every method, not just cash. A row
 *    indistinguishable from a collection is worse than no row at all, because a
 *    report would read it as revenue.
 *  - **Allocation.** A refund may never exceed what a method actually
 *    collected, and a return larger than the collected total must reduce a debt
 *    rather than invent money that was never taken.
 *  - **The customer account.** What a return leaves owing is the sum of dated
 *    movements, not a number somebody edited on the original invoice.
 */
class SaleReturnSettlementTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $user;

    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->otherBusiness = Business::factory()->create();
    }

    /** A stock-keeping product with 100 units on hand. */
    private function product(string $costPrice = '60.00', string $sellingPrice = '100.00'): Product
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

        // Pricing now lives on inventory batches, not on the product.
        InventoryBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'purchase_item_id' => null,
            'batch_number' => 'TEST-'.fake()->unique()->numerify('######'),
            'received_date' => now()->toDateString(),
            'expiry_date' => null,
            'received_quantity' => '100',
            'remaining_quantity' => '100',
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'mrp' => null,
            'selling_code' => null,
        ]);

        return $product;
    }

    /**
     * A customer of this business, created through the service.
     *
     * Going through `CustomerService` rather than the model is deliberate: that
     * is the path that records the opening balance as a ledger movement, so a
     * fixture built any other way would start its account somewhere the real
     * flow never produces.
     */
    private function customer(string $openingBalance = '0'): Customer
    {
        return $this->app->make(CustomerService::class)->create($this->business->id, [
            'name' => 'Settlement Customer',
            'opening_balance' => $openingBalance,
        ]);
    }

    /**
     * Record a sale through the API.
     *
     * Always over HTTP, so the fixture carries the payment rows and `unit_cost`
     * snapshots the rest of the system would really have written.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function sale(array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => now()->toDateString(),
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);
        $sale = $response->json('data');

        return [$sale, array_map('intval', array_column($sale['items'], 'id'))];
    }

    /** A sale of `$quantity` units at ৳100, paid with one method. */
    private function salePaidWith(Product $product, string $paidAmount, string $method, ?Customer $customer = null, string $quantity = '10'): array
    {
        return $this->sale(
            [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '100']],
            [
                'paid_amount' => $paidAmount,
                'payment_method' => $method,
                'customer_id' => $customer?->id,
            ]
        );
    }
/**
     * Post a return and assert it was accepted.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function returnSale(int $saleId, array $items, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/sale-returns', array_merge([
            'sale_id' => $saleId,
            'items' => $items,
        ], $extra));

        $response->assertStatus(201);

        return $response->json('data');
    }

    /** A return of `$quantity` units of one invoice line. */
    private function line(int $saleItemId, int $quantity): array
    {
        return [['sale_item_id' => $saleItemId, 'quantity' => $quantity]];
    }

    /** Open this user's drawer, so automatic cash movements have somewhere to go. */
    private function openRegister(): CashRegister
    {
        $response = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 0]);
        $response->assertStatus(201);

        return CashRegister::findOrFail($response->json('data.id'));
    }

    /** Every refund movement posted on one register. */
    private function refundMovements(CashRegister $register): Collection
    {
        return CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_REFUND)
            ->get();
    }

    /** Every refund payment recorded, anywhere. */
    private function refundPayments(): Collection
    {
        return Payment::query()->refunds()->get();
    }

    /** The ledger entries of one customer, oldest first. */
    private function ledger(Customer $customer): Collection
    {
        return CustomerLedgerEntry::query()
            ->forBusiness($this->business->id)
            ->forCustomer($customer->id)
            ->orderBy('id')
            ->get();
    }

    /** The derived balance of one customer. */
    private function balance(Customer $customer): string
    {
        return $this->app->make(CustomerLedgerService::class)->balance($customer);
    }

    // ------------------------------------------------------------------
    // Refund payments: recording that money left the business
    // ------------------------------------------------------------------

    /** @test */
    public function an_ordinary_sale_payment_is_still_recorded_as_an_inbound_payment(): void
    {
        $product = $this->product();
        [$sale] = $this->salePaidWith($product, '400', Payment::PAYMENT_METHOD_CASH);

        $payment = Payment::query()->where('sale_id', $sale['id'])->firstOrFail();

        $this->assertSame(Payment::TYPE_PAYMENT, $payment->type);
        $this->assertNull($payment->sale_return_id, 'An inbound payment belongs to no return.');
        $this->assertFalse($payment->isRefund());
        $this->assertSame('400.00', Money::format($payment->amount));
    }

    /**
     * The purchase-side `payments` columns are untouched.
     *
     * `PurchaseService` records what was paid on the purchase row itself and
     * writes no `Payment` of its own, so there is no purchase payment flow to
     * exercise end to end. What has to hold is that the column still accepts a
     * purchase payment and that such a row reads as an inbound payment — the
     * `sale_return_id` / `type` additions must not narrow what the table can
     * already express.
     */
    /** @test */
    public function a_purchase_payment_is_still_representable_as_an_inbound_payment(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $purchase = \App\Models\Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
        ]);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'purchase_id' => $purchase->id,
            'payment_method' => Payment::PAYMENT_METHOD_BANK,
            'amount' => 250,
        ]);

        // The direction defaults to an inbound payment, and the refund link
        // stays empty exactly as it did before the columns were added.
        $this->assertSame(Payment::TYPE_PAYMENT, $payment->fresh()->type);
        $this->assertFalse($payment->fresh()->isRefund());
        $this->assertNull($payment->fresh()->sale_return_id);
        $this->assertNull($payment->fresh()->sale_id);
        $this->assertSame($purchase->id, $payment->fresh()->purchase_id);
    }

    /** @test */
    public function a_cash_return_records_a_refund_payment_alongside_the_drawer_movement(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CASH);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // The two records describe one event from two sides: the payment is the
        // money, the movement is the drawer it left.
        $payments = $this->refundPayments();
        $this->assertCount(1, $payments);
        $this->assertSame('400.00', Money::format($payments->first()->amount));
        $this->assertSame(Payment::PAYMENT_METHOD_CASH, $payments->first()->payment_method);

        $this->assertCount(1, $this->refundMovements($register));
        $this->assertSame('400.00', Money::format($this->refundMovements($register)->first()->amount));
        $this->assertSame('400.00', $ret['settlement']['refunded']);
    }

    /** @test */
    public function a_refund_payment_can_never_be_mistaken_for_a_sale_payment(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CASH);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $refund = $this->refundPayments()->first();

        // The direction flag, the return link and the deliberately null
        // `sale_id` together make it impossible to read this row as money in.
        $this->assertSame(Payment::TYPE_REFUND, $refund->type);
        $this->assertTrue($refund->isRefund());
        $this->assertSame($ret['id'], $refund->sale_return_id);
        $this->assertNull($refund->sale_id, 'A refund must not sit in the sale payment list.');

        // Consequently the sale's own payments are untouched by the return.
        $this->assertCount(1, Sale::findOrFail($sale['id'])->payments);
        $this->assertCount(1, Payment::query()->inbound()->where('sale_id', $sale['id'])->get());
    }

    /** @test */
    public function a_refund_payment_reaches_the_return_it_settles(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CASH);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $refund = $this->refundPayments()->first();
        $this->assertInstanceOf(SaleReturn::class, $refund->saleReturn);
        $this->assertSame($ret['id'], $refund->saleReturn->getKey());
    }

    /** @test */
    public function the_return_response_exposes_its_settlement_and_refund_payments(): void
    {
        $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CASH);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $this->assertSame([
            'refunded' => '400.00',
            'credited' => '0.00',
            'by_method' => ['cash' => '400.00'],
        ], $ret['settlement']);

        $this->assertCount(1, $ret['refund_payments']);
        $this->assertSame(Payment::TYPE_REFUND, $ret['refund_payments'][0]['type']);
        $this->assertSame($ret['id'], $ret['refund_payments'][0]['sale_return_id']);
    }

    // ------------------------------------------------------------------
    // Non-cash refunds
    // ------------------------------------------------------------------

    /**
     * Each non-cash method records its own refund, and none may touch the
     * drawer — no cash ever went in, so none can come out.
     *
     * @test
     * @dataProvider nonCashMethods
     */
    public function a_non_cash_return_records_a_refund_payment_and_leaves_the_drawer_alone(string $method): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', $method);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $payments = $this->refundPayments();
        $this->assertCount(1, $payments, 'The refund must be recorded even though no cash moves.');
        $this->assertSame($method, $payments->first()->payment_method);
        $this->assertSame('400.00', Money::format($payments->first()->amount));
        $this->assertSame($ret['id'], $payments->first()->sale_return_id);

        $this->assertCount(0, $this->refundMovements($register), 'A card refund cannot empty the drawer.');
        $this->assertSame(
            '0.00',
            (string) $this->getJson("/api/v1/cash-registers/{$register->id}")->json('data.current_balance')
        );
    }

    /** @return array<string, array<int, string>> */
    public static function nonCashMethods(): array
    {
        return [
            'card' => [Payment::PAYMENT_METHOD_CARD],
            'bank' => [Payment::PAYMENT_METHOD_BANK],
            'cheque' => [Payment::PAYMENT_METHOD_CHEQUE],
        ];
    }

    /** @test */
    public function a_card_return_does_not_touch_the_drawer(): void
    {
        $register = $this->openRegister();
        $product = $this->product();
        [$sale, $itemIds] = $this->salePaidWith($product, '1000', Payment::PAYMENT_METHOD_CARD);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $this->assertCount(0, $this->refundMovements($register));
        $this->assertSame(
            '0.00',
            (string) $this->getJson("/api/v1/cash-registers/{$register->id}")->json('data.current_balance')
        );
    }

    // ------------------------------------------------------------------
    // Mixed settlement: never refunding more than was collected
    // ------------------------------------------------------------------

    /**
     * A sale paid partly in cash and partly by card, with a due remainder.
     *
     * `POST /sales/{id}/payments` records the second method, so the fixture is a
     * genuinely mixed sale rather than one assembled by hand.
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function mixedSale(Customer $customer, string $cash = '400', string $card = '300'): array
    {
        [$sale, $itemIds] = $this->salePaidWith(
            $this->product(),
            $cash,
            Payment::PAYMENT_METHOD_CASH,
            $customer
        );

        if (Money::greaterThan($card, '0')) {
            $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'amount' => $card,
                'payment_method' => Payment::PAYMENT_METHOD_CARD,
            ])->assertStatus(201);
        }

        return [$sale, $itemIds];
    }

    /**
     * The required accounting example, verified end to end.
     *
     * Total 1,000 paid 400 cash + 300 card, so 300 is due. A 500 return settles
     * as 400 cash + 100 card without inventing more of either, and the 300
     * receivable survives untouched.
     *
     * @test
     */
    public function the_accounting_example_settles_a_return_within_the_money_actually_collected(): void
    {
        $register = $this->openRegister();
        $customer = $this->customer();
        [$sale, $itemIds] = $this->mixedSale($customer);

        // The fixture itself: 400 cash + 300 card against a 1,000 invoice.
        $this->assertSame('700.00', Money::format(Sale::findOrFail($sale['id'])->paid_amount));
        $this->assertSame('300.00', Money::format(Sale::findOrFail($sale['id'])->due_amount));
        $this->assertSame('300.00', $this->balance($customer), 'The unpaid remainder is the receivable.');

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 5));

        $this->assertSame('500.00', $ret['refund_amount']);
        $this->assertSame([
            'refunded' => '500.00',
            'credited' => '0.00',
            'by_method' => ['cash' => '400.00', 'card' => '100.00'],
        ], $ret['settlement']);

        // 400 cash leaves the drawer; the card portion cannot.
        $this->assertCount(1, $this->refundMovements($register));
        $this->assertSame('400.00', Money::format($this->refundMovements($register)->first()->amount));

        // A fully settled return leaves the receivable exactly as it was.
        $this->assertSame('300.00', $this->balance($customer));
    }

    /** @test */
    public function a_return_larger_than_the_collected_total_credits_the_remainder_instead_of_fabricating_refunds(): void
    {
        $register = $this->openRegister();
        $customer = $this->customer();
        [$sale, $itemIds] = $this->mixedSale($customer);

        // The whole 1,000 comes back, but only 700 was ever collected.
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $this->assertSame('1000.00', $ret['refund_amount']);
        $this->assertSame([
            'refunded' => '700.00',
            // The unpaid 300 is relieved on the account, not paid out.
            'credited' => '300.00',
            'by_method' => ['cash' => '400.00', 'card' => '300.00'],
        ], $ret['settlement']);

        // No cash beyond what was drawn, and no card beyond what was charged.
        $this->assertCount(1, $this->refundMovements($register));
        $this->assertSame('400.00', Money::format($this->refundMovements($register)->first()->amount));
        $this->assertSame(2, $this->refundPayments()->count(), 'One refund per method, never more.');

        // The receivable is cleared by the credited remainder.
        $this->assertSame('0.00', $this->balance($customer));
    }

    /** @test */
    public function a_refund_never_exceeds_the_cash_actually_collected(): void
    {
        $this->openRegister();
        // ৳100 of cash against a ৳1,000 invoice.
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '100', Payment::PAYMENT_METHOD_CASH);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $this->assertSame('1000.00', $ret['refund_amount']);
        $this->assertSame('100.00', $ret['settlement']['refunded'], 'Capped at the cash actually taken.');
        $this->assertSame('900.00', $ret['settlement']['credited']);
    }

    /** @test */
    public function an_explicit_refund_split_is_honoured_within_each_methods_ceiling(): void
    {
        $this->openRegister();
        $customer = $this->customer();
        [$sale, $itemIds] = $this->mixedSale($customer);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 5), [
            'refunds' => [
                ['payment_method' => Payment::PAYMENT_METHOD_CARD, 'amount' => 200],
                ['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => 300],
            ],
        ]);

        // The cashier's split, exactly as asked for.
        $this->assertSame([
            'refunded' => '500.00',
            'credited' => '0.00',
            'by_method' => ['card' => '200.00', 'cash' => '300.00'],
        ], $ret['settlement']);
    }

    /** @test */
    public function an_explicit_refund_cannot_exceed_what_its_method_collected(): void
    {
        $this->openRegister();
        $customer = $this->customer();
        // Only ৳400 of cash exists to give back.
        [$sale, $itemIds] = $this->mixedSale($customer);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 6), [
            'refunds' => [['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => 500]],
        ]);

        // Asked for 500 cash, only 400 was ever taken, so 400 is paid and the
        // other 100 falls to the remaining method.
        $this->assertSame('400.00', $ret['settlement']['by_method'][Payment::PAYMENT_METHOD_CASH]);
        $this->assertSame('600.00', $ret['settlement']['refunded']);
        $this->assertSame('0.00', $ret['settlement']['credited']);
    }

    /** @test */
    public function an_unknown_or_non_positive_refund_is_refused(): void
    {
        $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        foreach ([
            'an unknown method' => ['payment_method' => 'bitcoin', 'amount' => 100],
            // `due` is not a method money can be paid out through.
            'the due method' => ['payment_method' => Payment::PAYMENT_METHOD_DUE, 'amount' => 100],
            'a negative amount' => ['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => -50],
            'a zero amount' => ['payment_method' => Payment::PAYMENT_METHOD_CASH, 'amount' => 0],
        ] as $case => $refund) {
            $this->postJson('/api/v1/sale-returns', [
                'sale_id' => $sale['id'],
                'items' => $this->line($itemIds[0], 1),
                'refunds' => [$refund],
            ])->assertStatus(422, "Refusing {$case} must fail validation.");
        }

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->refundPayments());
    }

    // ------------------------------------------------------------------
    // The customer account
    // ------------------------------------------------------------------

    /** @test */
    public function an_opening_balance_becomes_the_first_movement_of_the_account(): void
    {
        $customer = $this->customer('5000');

        $entries = $this->ledger($customer);
        $this->assertCount(1, $entries, 'The opening figure must be a movement, not an assumption.');
        $this->assertSame(CustomerLedgerEntry::TYPE_OPENING_BALANCE, $entries->first()->type);
        $this->assertSame('5000.00', Money::format($entries->first()->amount));
        $this->assertSame('5000.00', $this->balance($customer));
    }

    /** @test */
    public function a_customer_opened_at_zero_starts_with_no_entries(): void
    {
        $customer = $this->customer();

        // An entry that moves nothing would only add noise to a ledger whose
        // whole value is that every row means something.
        $this->assertCount(0, $this->ledger($customer));
        $this->assertSame('0.00', $this->balance($customer));
    }

    /** @test */
    public function opening_balance_stays_historical_and_is_never_rewritten(): void
    {
        $customer = $this->customer('1000');
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 1));

        // The account moved, but the column still says how it was opened.
        $this->assertSame('1000.00', Money::format($customer->fresh()->opening_balance));
        $this->assertSame(CustomerLedgerEntry::TYPE_OPENING_BALANCE, $this->ledger($customer)->first()->type);
    }

    /** @test */
    public function a_credit_sale_creates_a_receivable_movement(): void
    {
        $customer = $this->customer();
        $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        $entries = $this->ledger($customer);
        $this->assertCount(1, $entries);
        $this->assertSame(CustomerLedgerEntry::TYPE_SALE_DUE, $entries->first()->type);
        $this->assertSame('1000.00', Money::format($entries->first()->amount));
        $this->assertSame('1000.00', $this->balance($customer));
    }

    /** @test */
    public function a_fully_paid_sale_creates_no_receivable(): void
    {
        $customer = $this->customer();
        $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH, $customer);

        // Nothing was ever owed, so a movement would be a receivable invented
        // and immediately cancelled.
        $this->assertCount(0, $this->ledger($customer));
        $this->assertSame('0.00', $this->balance($customer));
    }

    /** @test */
    public function taking_a_payment_reduces_the_receivable(): void
    {
        $customer = $this->customer();
        [$sale] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);
        $this->assertSame('1000.00', $this->balance($customer));

        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 400,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ])->assertStatus(201);

        $entries = $this->ledger($customer);
        $this->assertCount(2, $entries);
        $this->assertSame(CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, $entries->last()->type);
        $this->assertSame('-400.00', Money::format($entries->last()->amount));
        $this->assertSame('600.00', $this->balance($customer));
    }

    /** @test */
    public function a_credit_sale_return_reduces_the_receivable_by_the_returned_amount(): void
    {
        $this->openRegister();
        $customer = $this->customer();
        // 1,000 on credit: nothing collected, so nothing can be paid back.
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        $this->assertSame('300.00', $ret['refund_amount']);
        $this->assertSame('300.00', $ret['settlement']['credited']);
        $this->assertCount(0, $this->refundPayments(), 'No money was collected, so none can go back.');

        $entries = $this->ledger($customer);
        $this->assertCount(2, $entries);
        $this->assertSame(CustomerLedgerEntry::TYPE_SALE_DUE, $entries->first()->type);
        $this->assertSame(CustomerLedgerEntry::TYPE_SALE_RETURN, $entries->last()->type);
        $this->assertSame('-300.00', Money::format($entries->last()->amount));

        // 1,000 owed, 300 of it no longer owed.
        $this->assertSame('700.00', $this->balance($customer));
    }

    /** @test */
    public function a_return_never_rewrites_the_original_sale(): void
    {
        $customer = $this->customer();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);
        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        // The invoice keeps the figures the customer was given. The reversal
        // lives on the return and in the ledger, never on the sale.
        $fresh = Sale::findOrFail($sale['id']);
        $this->assertSame('1000.00', Money::format($fresh->grand_total));
        $this->assertSame('0.00', Money::format($fresh->paid_amount));
        $this->assertSame('1000.00', Money::format($fresh->due_amount));
        $this->assertSame('pending', $fresh->payment_status);
    }

    /** @test */
    public function a_fully_paid_sale_return_creates_no_receivable(): void
    {
        $this->openRegister();
        $customer = $this->customer();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH, $customer);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // The money genuinely left the business, so the customer's account is
        // untouched: nothing stopped being owed.
        $this->assertCount(0, $this->ledger($customer));
        $this->assertSame('0.00', $this->balance($customer));
    }

    /** @test */
    public function the_balance_accumulates_across_sales_payments_and_repeated_returns(): void
    {
        $this->openRegister();
        $customer = $this->customer('5000');

        // Opening 5,000, then a 1,000 credit sale: 6,000 outstanding.
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);
        $this->assertSame('6000.00', $this->balance($customer));

        // Two partial returns of goods that were never paid for: 300 then 200.
        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));
        $this->assertSame('5700.00', $this->balance($customer));
        $this->returnSale($sale['id'], $this->line($itemIds[0], 2));
        $this->assertSame('5500.00', $this->balance($customer));

        // Opening 5,000 + 1,000 due - 500 returned = 5,500 still owed.
        $this->assertSame('5500.00', $this->balance($customer));
        $this->assertCount(4, $this->ledger($customer), 'Every movement is its own dated row.');

        // The invoice still shows ৳1,000 due — history is never rewritten — so a
        // ৳500 payment is accepted against it. But only ৳500 of it is genuinely
        // still owed (the returns already relieved ৳500), so exactly ৳500
        // reaches the customer's account and the balance lands on the original
        // opening figure.
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 500,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ])->assertStatus(201);

        $this->assertSame('5000.00', $this->balance($customer), 'Only the original 5,000 is left.');
        $this->assertSame('500.00', Money::format(Sale::findOrFail($sale['id'])->due_amount), 'The invoice keeps its own figure.');
        $this->assertCount(5, $this->ledger($customer));
    }

    /** @test */
    public function a_payment_never_relieves_money_a_return_already_wrote_off(): void
    {
        $customer = $this->customer();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        // Return 900 of the 1,000 owed, so 100 is still outstanding.
        $this->returnSale($sale['id'], $this->line($itemIds[0], 9));
        $this->assertSame('100.00', $this->balance($customer));

        // Paying the invoice's full 1,000 would relieve 900 the return already
        // wrote off. Only the remaining 100 is posted, so the balance lands on
        // zero rather than on a phantom negative.
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 1000,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
        ])->assertStatus(201);

        $this->assertSame('0.00', $this->balance($customer));
        $this->assertSame(3, $this->ledger($customer)->count(), 'sale_due, sale_return, customer_payment');
    }

    /** @test */
    public function a_return_of_goods_that_were_paid_for_leaves_the_account_alone(): void
    {
        $this->openRegister();
        $customer = $this->customer('5000');

        // Fully paid, so the whole 1,000 comes back out of the drawer rather
        // than off the debt.
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH, $customer);
        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        $this->assertSame('5000.00', $this->balance($customer));
        $this->assertCount(1, $this->ledger($customer), 'Only the opening balance is on the account.');
    }

    /** @test */
    public function a_negative_balance_reads_as_credit_the_business_holds(): void
    {
        $ledger = $this->app->make(CustomerLedgerService::class);
        $customer = $this->customer();

        // A hand-entered correction is the only way to reach a negative balance
        // deliberately, and its sign is stored exactly as given.
        $ledger->recordAdjustment($customer, '-250.00', $this->user->id, 'Goods returned before opening');

        $entries = $this->ledger($customer);
        $this->assertCount(1, $entries);
        $this->assertSame(CustomerLedgerEntry::TYPE_ADJUSTMENT, $entries->first()->type);
        $this->assertSame('-250.00', Money::format($entries->first()->amount));
        $this->assertSame('-250.00', $this->balance($customer));
    }

    /** @test */
    public function a_zero_adjustment_is_refused_rather_than_recorded(): void
    {
        $ledger = $this->app->make(CustomerLedgerService::class);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $ledger->recordAdjustment($this->customer(), '0', $this->user->id);
    }

    // ------------------------------------------------------------------
    // Paying a return twice
    // ------------------------------------------------------------------

    /** @test */
    public function the_database_refuses_a_second_refund_of_the_same_method_for_one_return(): void
    {
        $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        // `unique(sale_return_id, payment_method)` is the actual guarantee: a
        // replay that somehow reached the insert would fail here rather than
        // quietly paying the customer twice.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Payment::create([
            'business_id' => $this->business->id,
            'sale_return_id' => $ret['id'],
            'type' => Payment::TYPE_REFUND,
            'payment_method' => Payment::PAYMENT_METHOD_CASH,
            'amount' => 400,
        ]);
    }

    /** @test */
    public function the_unique_index_leaves_ordinary_payments_free_to_repeat_per_sale(): void
    {
        [$sale] = $this->salePaidWith($this->product(), '400', Payment::PAYMENT_METHOD_CASH);

        // SQL treats NULLs as distinct in a unique index, so every existing sale
        // and purchase payment — all with `sale_return_id IS NULL` — is
        // unaffected by the index the refund protection relies on.
        for ($i = 0; $i < 3; $i++) {
            Payment::create([
                'business_id' => $this->business->id,
                'sale_id' => $sale['id'],
                'type' => Payment::TYPE_PAYMENT,
                'payment_method' => Payment::PAYMENT_METHOD_CASH,
                'amount' => 100,
            ]);
        }

        $this->assertSame(4, Payment::query()->where('sale_id', $sale['id'])->count());
    }

    /** @test */
    public function a_replayed_request_cannot_pay_the_same_return_twice(): void
    {
        $register = $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        // The exact same request twice: return all 10 units, then ask again.
        $this->returnSale($sale['id'], $this->line($itemIds[0], 10));

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 10),
        ])->assertStatus(409);

        // One return, one refund payment, one drawer movement — the replay
        // settled nothing. The quantity guard refuses it because those units have
        // already come back, and the sale row is held with `lockForUpdate()` for
        // the whole transaction, so two concurrent replays serialise rather than
        // both passing the check.
        $this->assertSame(1, SaleReturn::query()->count());
        $this->assertCount(1, $this->refundPayments());
        $this->assertSame('1000.00', Money::format($this->refundPayments()->sole()->amount));
        $this->assertCount(1, $this->refundMovements($register));
    }

    /** @test */
    public function repeated_partial_returns_accumulate_against_a_single_ceiling(): void
    {
        $register = $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        // Three returns of 3 units, ৳300 each.
        foreach ([3, 3, 3] as $quantity) {
            $this->returnSale($sale['id'], $this->line($itemIds[0], $quantity));
        }

        // ৳900 of cash went back, and never more than the ৳1000 taken.
        $this->assertSame('900.00', Money::format($this->refundPayments()->sum('amount')));
        $this->assertSame('900.00', Money::format($this->refundMovements($register)->sum('amount')));

        // The invoice is exactly as it was sold.
        $this->assertSame('1000.00', Money::format(Sale::findOrFail($sale['id'])->grand_total));
        $this->assertSame('1000.00', Money::format(Sale::findOrFail($sale['id'])->paid_amount));
    }

    // ------------------------------------------------------------------
    // Atomicity: the return, its settlement and its account movement
    // ------------------------------------------------------------------

    /** @test */
    public function a_return_never_leaves_a_refund_without_its_return(): void
    {
        $this->openRegister();
        $customer = $this->customer();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 3));

        // Every refund points at a return that exists, in the same business.
        foreach ($this->refundPayments() as $refund) {
            $this->assertNotNull($refund->saleReturn);
            $this->assertSame($this->business->id, $refund->saleReturn->business_id);
            $this->assertSame($this->business->id, $refund->business_id);
        }

        // And every account movement points at the return that caused it.
        foreach ($this->ledger($customer) as $entry) {
            if ($entry->type === CustomerLedgerEntry::TYPE_SALE_RETURN) {
                $this->assertInstanceOf(SaleReturn::class, $entry->reference);
            }
        }
    }

    /** @test */
    public function a_failure_after_the_settlement_rolls_back_every_related_change(): void
    {
        $register = $this->openRegister();
        // A non-zero opening balance, so there is a pre-existing ledger row to
        // tell apart from anything this return might have written.
        $customer = $this->customer('2000');
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '0', Payment::PAYMENT_METHOD_CASH, $customer);

        $movementsBefore = StockMovement::query()->count();
        $refundsBefore = $this->refundMovements($register)->count();

        // The account movement is written *last*: the return, its lines, the
        // restored stock, the refund payment and the drawer movement are all
        // already in the transaction by this point. Removing the table makes
        // that final write fail, which is the only way to prove the earlier ones
        // roll back with it rather than surviving on their own.
        //
        // The account is captured first, because dropping the table discards it
        // too and the rollback has to be judged against what was there before.
        $ledgerBefore = $this->ledger($customer)
            ->map(fn (CustomerLedgerEntry $entry): array => [$entry->type, $entry->amount])
            ->all();
        Schema::drop('customer_ledger_entries');

        $response = $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 2),
        ]);

        // A server error, sanitised into the standard envelope rather than
        // leaking the SQL that failed.
        $response->assertStatus(500)->assertJson(['success' => false]);

        // Nothing may survive: not the return, not the refund payment, not the
        // drawer movement, and not the restored stock.
        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->refundPayments());
        $this->assertSame($refundsBefore, $this->refundMovements($register)->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());

        // Restored so the account can be inspected, and so the rest of the run
        // still has the table it expects.
        Schema::create('customer_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Re-seed exactly what was there before, and confirm the return added
        // nothing on top of it: no `sale_due` and no `sale_return`.
        foreach ($ledgerBefore as [$type, $amount]) {
            CustomerLedgerEntry::create([
                'business_id' => $this->business->id,
                'customer_id' => $customer->id,
                'type' => $type,
                'amount' => $amount,
            ]);
        }

        $this->assertSame(
            $ledgerBefore,
            $this->ledger($customer)
                ->map(fn (CustomerLedgerEntry $entry): array => [$entry->type, $entry->amount])
                ->all(),
            'The failed return left the account exactly as it found it.'
        );
        $this->assertSame(0, CustomerLedgerEntry::query()
            ->where('type', CustomerLedgerEntry::TYPE_SALE_RETURN)
            ->count());
    }

    // ------------------------------------------------------------------
    // Tenancy and authorization
    // ------------------------------------------------------------------

    /** @test */
    public function a_return_of_another_business_sale_creates_nothing(): void
    {
        $foreignSale = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);

        // The foreign sale is simply not found for this tenant, so the request
        // never reaches the settlement at all.
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $foreignSale->id,
            'items' => [['sale_item_id' => 1, 'quantity' => 1]],
        ])->assertStatus(404);

        $this->assertSame(0, SaleReturn::query()->where('business_id', $this->business->id)->count());
        $this->assertCount(0, $this->refundPayments()->where('business_id', $this->business->id));
        $this->assertSame(0, CustomerLedgerEntry::query()->where('business_id', $this->business->id)->count());
    }

    /** @test */
    public function a_refund_is_never_reachable_from_another_tenant(): void
    {
        $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);
        $ret = $this->returnSale($sale['id'], $this->line($itemIds[0], 4));

        $refund = $this->refundPayments()->sole();
        $this->assertSame($this->business->id, $refund->business_id);

        // Acting as a fully permitted user of the other business, the return behind
        // the refund still does not exist — isolation is a data boundary, not a
        // side effect of lacking permission.
        $foreignUser = $this->createBusinessUser($this->otherBusiness);
        $this->grantAllCapabilities($foreignUser);
        Sanctum::actingAs($foreignUser);

        $this->getJson("/api/v1/sale-returns/{$ret['id']}")->assertStatus(404);
        $this->assertSame(0, $this->getJson('/api/v1/sale-returns')->json('data.pagination.total'));
    }

    /** @test */
    public function a_refund_cannot_be_written_against_another_business_return(): void
    {
        $foreignSale = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $foreignReturn = SaleReturn::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'sale_id' => $foreignSale->id,
        ]);

        $this->openRegister();
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        $this->returnSale($sale['id'], $this->line($itemIds[0], 1));

        // The refund belongs to this business's own return, never the foreign one.
        $refund = $this->refundPayments()->sole();
        $this->assertNotSame($foreignReturn->id, $refund->sale_return_id);
        $this->assertSame($this->business->id, $refund->business_id);
        $this->assertSame(0, $this->refundPayments()->where('business_id', $this->otherBusiness->id)->count());
    }

    /** @test */
    public function ledger_entries_are_scoped_to_their_own_business(): void
    {
        $mine = $this->customer('1000');
        $theirs = Customer::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'opening_balance' => 750,
        ]);

        // The foreign customer's account is invisible in both directions: the
        // tenant scope finds no rows for it, and this business's rows are all
        // filed against its own customer.
        $this->assertCount(0, CustomerLedgerEntry::query()
            ->forBusiness($this->business->id)
            ->forCustomer($theirs->id)
            ->get());

        $this->assertCount(1, CustomerLedgerEntry::query()->where('customer_id', $mine->id)->get());
        $this->assertSame(
            $this->business->id,
            CustomerLedgerEntry::query()->where('customer_id', $mine->id)->first()->business_id
        );
    }

    /** @test */
    public function a_suspended_user_cannot_settle_a_return(): void
    {
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        // Suspending the account behind a token that already exists: the
        // `active` middleware must still refuse it.
        $this->user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(401);

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->refundPayments());
    }

    /** @test */
    public function an_unauthenticated_return_is_refused(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => 1,
            'items' => [['sale_item_id' => 1, 'quantity' => 1]],
        ])->assertStatus(401);

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->refundPayments());
    }

    /** @test */
    public function a_user_without_the_return_capability_settles_nothing(): void
    {
        [$sale, $itemIds] = $this->salePaidWith($this->product(), '1000', Payment::PAYMENT_METHOD_CASH);

        // Can read sales, but holds no return capability at all.
        $user = User::factory()->create(['business_id' => $this->business->id]);
        $role = Role::firstOrCreate(
            ['business_id' => $this->business->id, 'slug' => 'no-returns'],
            ['name' => 'No Returns', 'description' => 'Fixture without the return capability.']
        );
        $role->permissions()->sync(
            Permission::firstOrCreate(['slug' => 'sales.view'], ['name' => 'View sales'])->getKey()
        );
        $user->roles()->syncWithoutDetaching([$role->getKey()]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => $this->line($itemIds[0], 1),
        ])->assertStatus(403);

        // The refund payment and the account movement are part of the same
        // authorised transaction as the return, so refusing the return must
        // leave none of them behind — and no extra permission was needed to
        // make the internal calls work.
        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertCount(0, $this->refundPayments());
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
    }
}