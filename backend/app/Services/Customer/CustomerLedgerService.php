<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes and reads the customer account ledger.
 *
 * The balance is **derived, never stored**: it is the sum of the entries, so an
 * entry can never disagree with the events that produced it and there is no
 * cached column to fall out of date. `customers.opening_balance` is left exactly
 * as entered — it is historical data about where the account started, not a
 * running total, and nothing in this service writes to it.
 *
 * ## Sign convention
 *
 * **Positive = the customer owes the business. Negative = the business owes the
 * customer.** Callers pass the magnitude and this service applies the sign the
 * type implies, so no caller has to remember which way a credit sale points.
 * That is the whole reason the sign lives here rather than at each call site: an
 * inverted entry would otherwise be entirely silent.
 *
 * Only {@see recordAdjustment()} takes a signed amount, because for a
 * correction the direction *is* the decision being made.
 */
final class CustomerLedgerService
{
    /**
     * The opening balance, recorded once when the customer is created.
     *
     * Written as its own entry rather than left implicit in the column so the
     * ledger is complete on its own: summing the rows gives the balance even if
     * nobody reads `customers`. Skipped for a zero opening balance, which would
     * add a row that says nothing.
     *
     * The column is deliberately *not* updated afterwards — this records where
     * the account began, and rewriting it would destroy the only evidence of
     * what the customer was originally opened with.
     */
    public function recordOpeningBalance(Customer $customer, ?int $userId): ?CustomerLedgerEntry
    {
        // Stored with the sign it was opened with: a customer who already held
        // an advance starts at a negative balance, and forcing it positive here
        // would invent a debt that never existed.
        return $this->record($customer, CustomerLedgerEntry::TYPE_OPENING_BALANCE, Money::format($customer->opening_balance), null, null, $userId);
    }

    /**
     * A credit sale: the customer now owes us more.
     *
     * @param  string  $dueAmount  The sale's `due_amount`, never the invoice total.
     */
    public function recordSaleDue(Customer $customer, string $dueAmount, int $saleId, ?int $userId): ?CustomerLedgerEntry
    {
        return $this->record($customer, CustomerLedgerEntry::TYPE_SALE_DUE, Money::format($dueAmount), Sale::class, $saleId, $userId);
    }

    /**
     * Money received from the customer, reducing what they owe.
     *
     * Recorded when a payment is taken against a sale that already had an
     * outstanding due. A payment that settles a sale at the moment of checkout
     * is *not* an entry: nothing was ever outstanding, so one would invent a
     * receivable and immediately cancel it.
     */
    public function recordCustomerPayment(Customer $customer, string $amount, int $paymentId, ?int $userId): ?CustomerLedgerEntry
    {
        return $this->record($customer, CustomerLedgerEntry::TYPE_CUSTOMER_PAYMENT, Money::negate(Money::format($amount)), Payment::class, $paymentId, $userId);
    }
/**
     * A return, reducing what the customer owes.
     *
     * Only the part of the return that was **not** settled by an actual refund
     * payment reaches the ledger, because only that part changes what is owed. A
     * return of ৳500 paid ৳400 back in cash and ৳100 back to the card was
     * settled in full and leaves the balance alone: that ৳500 left the drawer
     * and the merchant, not the customer's account. What does reach the ledger
     * is the portion covering goods that were never paid for — a credit sale,
     * or the unpaid remainder of a mixed one — because that is precisely what
     * stops being owed.
     *
     * @param  string  $unsettledAmount  Return amount minus its refund payments.
     */
    public function recordSaleReturn(Customer $customer, string $unsettledAmount, int $saleReturnId, ?int $userId): ?CustomerLedgerEntry
    {
        return $this->record(
            $customer,
            CustomerLedgerEntry::TYPE_SALE_RETURN,
            Money::negate(Money::format($unsettledAmount)),
            SaleReturn::class,
            $saleReturnId,
            $userId,
        );
    }

    /**
     * A correction entered by hand, with the sign the accountant chose.
     *
     * Stored exactly as given rather than flipped by type, because here the
     * direction *is* the decision. A zero amount is refused: an entry that
     * moves nothing would only add noise to a ledger whose whole value is that
     * every row means something.
     *
     * @throws BusinessRuleException when the amount is zero.
     */
    public function recordAdjustment(Customer $customer, string $amount, ?int $userId, ?string $notes = null): CustomerLedgerEntry
    {
        $amount = Money::format($amount);
        if (Money::isZero($amount)) {
            throw new BusinessRuleException('Adjustment amount cannot be zero.');
        }

        return $this->record($customer, CustomerLedgerEntry::TYPE_ADJUSTMENT, $amount, null, null, $userId, $notes);
    }

    /**
     * What this customer owes the business right now.
     *
     * Positive means they owe us, negative means we owe them. One aggregate
     * rather than loading the rows to sum them in PHP.
     */
    public function balance(Customer $customer): string
    {
        $sum = CustomerLedgerEntry::query()
            ->forBusiness($customer->business_id)
            ->forCustomer($customer->getKey())
            ->sum('amount');

        return Money::format($sum);
    }

    /**
     * The account history, oldest first.
     *
     * @return Collection<int, CustomerLedgerEntry>
     */
    public function entries(Customer $customer): Collection
    {
        return CustomerLedgerEntry::query()
            ->forBusiness($customer->business_id)
            ->forCustomer($customer->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Write one entry. Every public method funnels through here so the sign
     * convention is enforced in exactly one place.
     *
     * @param  string  $signedAmount  Already carries its sign.
     */
    private function record(
        Customer $customer,
        string $type,
        string $signedAmount,
        ?string $referenceType,
        ?int $referenceId,
        ?int $userId,
        ?string $notes = null,
    ): ?CustomerLedgerEntry {
        // An entry that moves no money is not recorded. Refusing to write one is
        // what keeps every row meaningful: a balance derived from the ledger
        // should never have to skip empty rows to be readable.
        if (Money::isZero($signedAmount)) {
            return null;
        }

        return CustomerLedgerEntry::create([
            // The tenant comes from the customer, itself already resolved within
            // the tenant, so a ledger row can never be filed against another
            // business by threading a foreign id around.
            'business_id' => $customer->business_id,
            'customer_id' => $customer->getKey(),
            'type' => $type,
            'amount' => $signedAmount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }
}