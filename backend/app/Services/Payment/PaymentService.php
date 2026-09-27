<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\CashRegister\CashRegisterService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sale payment handling against the existing `payments` table.
 */
final class PaymentService
{
    public const OVERPAY_MESSAGE = 'Payment exceeds the outstanding amount.';

    public function __construct(private readonly CashRegisterService $cashRegisters) {}

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
            $fresh->fill([
                'paid_amount' => $paid,
                'due_amount' => $newDue,
                'payment_status' => Money::isZero($paid)
                    ? Sale::PAYMENT_STATUS_PENDING
                    : (Money::equals($paid, Money::format($fresh->grand_total)) ? Sale::PAYMENT_STATUS_PAID : Sale::PAYMENT_STATUS_PARTIAL),
            ])->save();

            return $payment;
        });
    }
}
