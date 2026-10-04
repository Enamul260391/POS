<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Customer\CustomerLedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sale payment handling against the existing `payments` table.
 */
final class PaymentService
{
    public const OVERPAY_MESSAGE = 'Payment exceeds the outstanding amount.';

    public function __construct(
        private readonly CashRegisterService $cashRegisters,
        private readonly CustomerLedgerService $ledger,
    ) {}

    public function recordForSale(int $businessId, ?int $userId, Sale $sale, array $data): Payment
    {
        return DB::transaction(function () use ($businessId, $userId, $sale, $data): Payment {
            $fresh = Sale::query()->forBusiness($businessId)->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            $amount = Money::format($data['amount']);
            $due = Money::format($fresh->due_amount);
            if (Money::greaterThan($amount, $due)) {
                throw new BusinessRuleException(self::OVERPAY_MESSAGE);
            }

            $payment = Payment::create([
                'business_id' => $businessId,
                'sale_id' => $fresh->getKey(),
                'purchase_id' => null,
                // Explicit even though the column defaults to it, so the row
                // states its own direction rather than relying on a default that
                // a later migration could change.
                'type' => Payment::TYPE_PAYMENT,
                'payment_method' => $data['payment_method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'paid_at' => isset($data['paid_at']) ? $data['paid_at'] : now(),
                'received_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($payment->payment_method === Payment::PAYMENT_METHOD_CASH && $userId !== null) {
                $this->cashRegisters->postAutomaticTransaction(
                    $businessId,
                    $userId,
                    CashRegisterTransaction::TYPE_SALE,
                    $amount,
                    Payment::class,
                    (int) $payment->getKey(),
                    $data['notes'] ?? null,
                );
            }

            $paid = Money::add($fresh->paid_amount, $amount);
            $newDue = Money::subtract($fresh->grand_total, $paid);

            // The due as it stood *before* this payment. Captured here because
            // the save below zeroes it, and it is this figure that decides how
            // much of the payment was genuinely settling an outstanding debt.
            $dueBeforePayment = $due;

            $fresh->fill([
                'paid_amount' => $paid,
                'due_amount' => $newDue,
                'payment_status' => Money::isZero($paid)
                    ? Sale::PAYMENT_STATUS_PENDING
                    : (Money::equals($paid, Money::format($fresh->grand_total)) ? Sale::PAYMENT_STATUS_PAID : Sale::PAYMENT_STATUS_PARTIAL),
            ])->save();

            // Money received against a sale the customer already owed for, so it
            // reduces their balance — but only up to what was genuinely still
            // outstanding on that sale.
            //
            // The cap matters because a return already relieved the ledger while
            // `due_amount` deliberately still shows the invoice as written. A
            // payment that credited the full amount would therefore relieve money
            // the ledger had already written off, and a customer's balance could
            // drift below zero without any real advance to show for it.
            if ($fresh->customer_id !== null) {
                $customer = Customer::query()->forBusiness($businessId)->whereKey($fresh->customer_id)->first();
                if ($customer !== null) {
                    $outstanding = Money::subtract(
                        $dueBeforePayment,
                        $this->alreadyRelievedByReturns($businessId, $fresh)
                    );

                    // Never negative: an over-return against an already-settled
                    // sale leaves nothing outstanding to relieve, not a credit.
                    $relievable = Money::isNegative($outstanding) ? '0.00' : $outstanding;

                    $this->ledger->recordCustomerPayment(
                        $customer,
                        Money::greaterThan($amount, $relievable) ? $relievable : $amount,
                        (int) $payment->getKey(),
                        $userId
                    );
                }
            }

            return $payment;
        });
    }

    /**
     * How much of this sale's due its returns have already written off.
     *
     * Each return relieved the customer's account by whatever no refund payment
     * could cover. Summing that back here is what keeps a later payment from
     * relieving the same money twice — the invoice keeps its original `due`, and
     * this is where the part already settled by a return is accounted for.
     */
    private function alreadyRelievedByReturns(int $businessId, Sale $sale): string
    {
        // Entries of this type are always negative by construction, so summing
        // them and taking the magnitude answers "how much was written off".
        //
        // A plain `sum()` on a negative-signed column would return a negative
        // figure, which is what `Money::subtract()` needs to see so the cap can
        // never come out larger than the due.
        $relieved = CustomerLedgerEntry::query()
            ->forBusiness($businessId)
            ->where('type', CustomerLedgerEntry::TYPE_SALE_RETURN)
            ->where('reference_type', SaleReturn::class)
            ->whereIn('reference_id', $sale->saleReturns()->select('id'))
            ->sum('amount');

        return Money::negate(Money::format($relieved));
    }
}
