<?php

declare(strict_types=1);

namespace App\Services\SaleReturn;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Customer\CustomerLedgerService;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for customer returns.
 *
 * A return is treated as a **reversal of the economic effects** of the original
 * sale, recorded as its own dated document. Nothing on the original invoice is
 * rewritten: `sales`, `sale_items` and `payments` keep the figures the sale was
 * written with, and every later report nets the returns off them.
 *
 * Four things happen atomically, in this order:
 *
 *  1. **Quantity guard.** For every requested sale line the service sums what
 *     has already been returned across *all* prior returns of that sale and
 *     refuses anything above the remainder. The sale row is held with
 *     `lockForUpdate()` for the whole transaction, so two concurrent returns
 *     against the same invoice serialise instead of both passing the check.
 *  2. **Money.** Each line is refunded as its pro-rata share of the *stored*
 *     sale line total, so a partial return is exact and the shares of repeated
 *     partial returns always add up to the line they came from.
 *  3. **Inventory.** Each returned line restores its quantity through
 *     {@see InventoryService} as one `sale_return` ledger movement per line.
 *  4. **Refund.** The refund is settled against the money the sale actually
 *     collected — see {@see settleRefund()} — which posts at most one automatic
 *     `refund` cash-register movement and never touches the drawer for a sale
 *     that was not paid in cash.
 *
 * ## Cost of goods
 *
 * The reversal of cost is *derived*, never stored: a return line points at its
 * `sale_items` row, whose `unit_cost` is the snapshot taken when the sale was
 * written. Reading the cost through that pointer — rather than through the
 * product's current `cost_price` — is what makes the reversal use the cost
 * actually incurred. A line whose `unit_cost` is `NULL` stays `NULL`: no
 * historical cost is invented for it, and a later profit report keeps
 * withholding the figure exactly as it already does for such sales.
 */
final class SaleReturnService
{
    public const SORTABLE_COLUMNS = [
        'return_date', 'return_number', 'subtotal', 'refund_amount', 'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'created_at';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
        private readonly CustomerLedgerService $ledger,
    ) {}

    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = SaleReturn::query()->forBusiness($businessId)->with(['sale', 'saleReturnItems.saleItem']);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $n) use ($term): void {
                $n->where('return_number', 'like', $term)->orWhere('reason', 'like', $term);
            });
        }
        if (($filters['sale_id'] ?? null) !== null) {
            $query->where('sale_id', (int) $filters['sale_id']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        $col = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($col, self::SORTABLE_COLUMNS, true)) {
            $col = self::DEFAULT_SORT_COLUMN;
        }
        $dir = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($col, $dir)->orderBy('id')->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)));
    }

    public function find(int $businessId, int $id): SaleReturn
    {
        return SaleReturn::query()
            ->forBusiness($businessId)
            // `refundPayments` is what makes `settlement` resolvable in the
            // resource. It is one extra query per return, on the relation the
            // response is built around, and not eager-loaded in the list below:
            // a page of returns would otherwise pay for a settlement breakdown
            // the list view does not display.
            ->with(['sale', 'customer', 'saleReturnItems.product', 'saleReturnItems.saleItem', 'refundPayments'])
            ->whereKey($id)
            ->firstOrFail();
    }

    public function create(int $businessId, ?int $userId, array $data): SaleReturn
    {
        return DB::transaction(function () use ($businessId, $userId, $data): SaleReturn {
            $sale = Sale::query()->forBusiness($businessId)->whereKey((int) $data['sale_id'])->lockForUpdate()->firstOrFail();
            // Phase 27: `product` is eager loaded here. The stock-in loop below
            // reads `$saleItem->product` for every returned line, so without
            // this each line issued its own `select * from products` query.
            $items = $sale->saleItems()->with('product')->orderBy('id')->get();
            $byId = $items->keyBy('id');
            $returned = [];
            foreach ($sale->saleReturns()->with('saleReturnItems')->get() as $ret) {
                foreach ($ret->saleReturnItems as $ri) {
                    $sid = (int) $ri->sale_item_id;
                    $returned[$sid] = isset($returned[$sid]) ? Quantity::add($returned[$sid], $ri->quantity) : Quantity::format($ri->quantity);
                }
            }
            $seen = [];
            $lines = [];
            foreach ($data['items'] as $row) {
                $sid = (int) $row['sale_item_id'];
                if (isset($seen[$sid])) {
                    throw new BusinessRuleException('Duplicate sale item in return.');
                }
                $seen[$sid] = true;
                $saleItem = $byId->get($sid);
                if ($saleItem === null) {
                    throw new BusinessRuleException('One or more sale items are invalid.');
                }
                $qty = Quantity::format($row['quantity']);
                $soldQty = Quantity::format($saleItem->quantity);
                $remain = Quantity::subtract($soldQty, Quantity::format($returned[$sid] ?? 0));
                if (! Quantity::isPositive($remain) || Quantity::isNegative(Quantity::subtract($remain, $qty))) {
                    throw new BusinessRuleException('Return quantity exceeds remaining quantity.');
                }
                // Pro-rata share via bcmath (never floats): returned / sold, 10-digit scale.
                $share = bcdiv($qty, $soldQty, 10);
                $lines[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $qty,
                    'unit_price' => Money::format($saleItem->unit_price),
                    'total' => Money::multiply(Money::format($saleItem->total), $share),
                    // The tax embedded in that line total, taken as the same share
                    // so `tax` and `total` are two views of one number rather than
                    // two independent figures that can drift apart.
                    'tax' => Money::multiply(Money::format($saleItem->tax), $share),
                ];
            }
            $subtotal = '0.00';
            $tax = '0.00';
            foreach ($lines as $line) {
                $subtotal = Money::add($subtotal, $line['total']);
                $tax = Money::add($tax, $line['tax']);
            }
            $ret = SaleReturn::create([
                'business_id' => $businessId,
                'sale_id' => $sale->getKey(),
                'customer_id' => $sale->customer_id,
                'return_number' => $data['return_number'] ?? null,
                'return_date' => isset($data['return_date']) ? Carbon::parse($data['return_date'])->toDateString() : now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'subtotal' => $subtotal,
                // The reversed share of the tax the invoice lines carried. It is
                // derived from the same pro-rata share as the line total rather
                // than pinned to zero, so `tax` and `refund_amount` describe the
                // same money and a later report can split the reversal.
                'tax' => $tax,
                'refund_amount' => $subtotal,
                'status' => SaleReturn::STATUS_COMPLETED,
                'created_by' => $userId,
            ]);
            foreach ($lines as $line) {
                $ret->saleReturnItems()->create([
                    'sale_item_id' => $line['sale_item']->getKey(),
                    'product_id' => $line['sale_item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total' => $line['total'],
                ]);
                $product = $line['sale_item']->product;
                if ($product !== null && $product->tracksStock()) {
                    $this->inventory->record($product, $userId, StockMovement::TYPE_SALE_RETURN, Quantity::format($line['quantity']), 'Sale return #'.$ret->getKey(), SaleReturn::class, $ret->getKey());
                }
            }

            // Settlement happens after the return, its lines and its stock
            // movements are written but before the transaction commits, so the
            // refund payments, the drawer movement and the ledger entry either
            // all exist together or none of them do.
            $settlement = $this->settleRefund(
                $businessId,
                $userId,
                $sale,
                $ret,
                $subtotal,
                $data['refunds'] ?? null,
            );

            // Only the part of the return that no method could pay back is what
            // stops being owed. A return fully covered by refunds leaves the
            // balance untouched, so a cash sale's return never invents a
            // receivable for money that actually left the drawer.
            if ($ret->customer_id !== null) {
                $customer = Customer::query()->forBusiness($businessId)->whereKey($ret->customer_id)->first();
                if ($customer !== null) {
                    $this->ledger->recordSaleReturn($customer, $settlement['unsettled'], (int) $ret->getKey(), $userId);
                }
            }

            return $this->find($businessId, $ret->getKey());
        });
    }

    /**
     * Settle a refund against the money the sale actually collected.
     *
     * The sale is the only record of *how* it was paid, so it — not the caller —
     * decides where the money goes. Each method is refunded **only up to what was
     * actually collected through it**; what no method can cover is the unsettled
     * remainder, which the customer's ledger relieves rather than a fabricated
     * refund paying out.
     *
     * Each allocation writes a `refund` {@see Payment} linked to this return,
     * plus — for the cash portion only — one automatic `refund` cash-register
     * movement. Everything runs in the caller's transaction, so a failure
     * anywhere rolls the whole operation back.
     *
     * @param  string  $refundAmount  The reversal this return owes the customer.
     * @param  array<int, array<string, mixed>>|null  $requested  Client refund split.
     * @return array{refunded: string, unsettled: string}
     */
    private function settleRefund(int $businessId, ?int $userId, Sale $sale, SaleReturn $saleReturn, string $refundAmount, ?array $requested): array
    {
        if (Money::isZero($refundAmount)) {
            return ['refunded' => '0.00', 'unsettled' => '0.00'];
        }

        $refunded = '0.00';
        foreach ($this->refundPlan($businessId, $sale, $refundAmount, $requested) as $allocation) {
            Payment::create([
                'business_id' => $businessId,
                // Deliberately null: a refund is written against the return, so
                // it can never appear in `$sale->payments()` or be summed as an
                // inbound collection. The path back to the sale is
                // `payment -> sale_return -> sale`.
                'sale_id' => null,
                'sale_return_id' => $saleReturn->getKey(),
                'type' => Payment::TYPE_REFUND,
                'payment_method' => $allocation['payment_method'],
                'amount' => $allocation['amount'],
                'paid_at' => now(),
                'received_by' => $userId,
                'notes' => 'Refund for sale return #'.$saleReturn->getKey(),
            ]);

            if ($allocation['payment_method'] === Payment::PAYMENT_METHOD_CASH) {
                $this->postCashRefund($businessId, $userId, $saleReturn, $allocation['amount']);
            }

            $refunded = Money::add($refunded, $allocation['amount']);
        }

        $unsettled = Money::subtract($refundAmount, $refunded);

        return [
            'refunded' => $refunded,
            // Clamped at zero so rounding can never produce a negative remainder,
            // which would read as the business owing the customer money it never
            // took.
            'unsettled' => Money::isNegative($unsettled) ? '0.00' : $unsettled,
        ];
    }

    /**
     * Split a refund across methods without exceeding what each collected.
     *
     * The default order is {@see Payment::REFUND_METHODS}: cash first, because
     * it is the only method whose reversal touches the drawer, then the
     * merchant-rail methods in a fixed sequence. The order is fixed rather than
     * derived so the same sale always refunds the same way, instead of depending
     * on the order rows happen to come back in.
     *
     * A client may override the split, but the per-method ceiling is applied to
     * an explicit request exactly as it is to the default: the ceiling protects
     * the books, not the caller's intent.
     *
     * @param  array<int, array<string, mixed>>|null  $requested
     * @return array<int, array{payment_method: string, amount: string}>
     */
    private function refundPlan(int $businessId, Sale $sale, string $refundAmount, ?array $requested): array
    {
        $remaining = $refundAmount;
        $allocations = [];
        $claimed = [];

        foreach ($this->normaliseRequestedRefunds($requested) as $method => $asked) {
            if (Money::isZero($remaining)) {
                break;
            }
            $amount = $this->cappedAt($asked, $this->netCollectedThrough($businessId, $sale, $method));
            if (Money::isZero($amount)) {
                continue;
            }
            $allocations[] = ['payment_method' => $method, 'amount' => $amount];
            $claimed[] = $method;
            $remaining = Money::subtract($remaining, $amount);
        }

        // Whatever the client did not allocate follows the default order, which
        // is what makes a return with no `refunds` field still settle correctly.
        foreach (Payment::REFUND_METHODS as $method) {
            if (Money::isZero($remaining)) {
                break;
            }
            if (in_array($method, $claimed, true)) {
                continue;
            }
            $amount = $this->cappedAt($remaining, $this->netCollectedThrough($businessId, $sale, $method));
            if (Money::isZero($amount)) {
                continue;
            }
            $allocations[] = ['payment_method' => $method, 'amount' => $amount];
            $remaining = Money::subtract($remaining, $amount);
        }

        return $allocations;
    }

    /**
     * The smaller of what was asked for and what may actually be refunded.
     */
    private function cappedAt(string $wanted, string $available): string
    {
        if (Money::isNegative($wanted) || Money::isNegative($available)) {
            return '0.00';
        }

        return Money::greaterThan($wanted, $available) ? $available : $wanted;
    }
/**
     * Money still refundable through one method.
     *
     * Gross collected for the sale minus every refund already paid through the
     * same method across *all* of its returns. Accumulating this way is what
     * makes repeated partial returns correct: each one sees what is genuinely
     * left rather than the full original collection, so the same ৳400 can never
     * be refunded twice.
     */
    private function netCollectedThrough(int $businessId, Sale $sale, string $method): string
    {
        $collected = Payment::query()
            ->forBusiness($businessId)
            ->inbound()
            ->where('sale_id', $sale->getKey())
            ->where('payment_method', $method)
            ->sum('amount');

        // Refunds hang off the returns of this sale rather than off the sale
        // itself, so the ones already made are found through those returns.
        $refunded = Payment::query()
            ->forBusiness($businessId)
            ->refunds()
            ->whereIn('sale_return_id', $sale->saleReturns()->select('id'))
            ->where('payment_method', $method)
            ->sum('amount');

        $net = Money::subtract(Money::format($collected), Money::format($refunded));

        return Money::isNegative($net) ? '0.00' : $net;
    }

    /**
     * Take the cash portion out of the drawer.
     *
     * Reached only for a cash allocation. With no register open,
     * `postAutomaticTransaction()` returns `null` and the refund payment is still
     * written — the pre-existing behaviour, kept deliberately: the payment
     * records the financial event that happened, while the drawer movement is
     * the physical one, and inventing a cash movement with nowhere to post it
     * would make the register disagree with reality.
     */
    private function postCashRefund(int $businessId, ?int $userId, SaleReturn $saleReturn, string $amount): void
    {
        if ($userId === null) {
            return;
        }

        $this->cashRegisters->postAutomaticTransaction(
            $businessId,
            $userId,
            CashRegisterTransaction::TYPE_REFUND,
            $amount,
            SaleReturn::class,
            (int) $saleReturn->getKey(),
            'Refund for sale return #'.$saleReturn->getKey(),
        );
    }

    /**
     * Collapse a requested refund split to one amount per method.
     *
     * A repeated method is summed rather than rejected, so two rows naming the
     * same method cannot slip past the per-method ceiling by being split apart.
     * Unknown methods and non-positive amounts are dropped here; the request
     * layer has already refused them with a validation error.
     *
     * @param  array<int, array<string, mixed>>|null  $requested
     * @return array<string, string>
     */
    private function normaliseRequestedRefunds(?array $requested): array
    {
        $byMethod = [];
        foreach ($requested ?? [] as $row) {
            $method = (string) ($row['payment_method'] ?? '');
            $amount = Money::format($row['amount'] ?? 0);
            if (! in_array($method, Payment::REFUND_METHODS, true) || Money::isZero($amount) || Money::isNegative($amount)) {
                continue;
            }
            $byMethod[$method] = Money::add($byMethod[$method] ?? '0.00', $amount);
        }

        return $byMethod;
    }
}
