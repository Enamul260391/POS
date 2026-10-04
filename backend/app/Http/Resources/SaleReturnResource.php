<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled sale-return representation.
 */
class SaleReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The cost of goods this return puts back, summed from the lines.
        //
        // It is a *reversal*: money already spent on stock that is coming back
        // onto the shelf, so a later report reads it as a negative cost against
        // the revenue the return itself reverses. `null` whenever any line has no
        // recorded historical cost — the same "profit unavailable" condition the
        // sales report already withholds a figure for, reported identically
        // rather than as a confident zero.
        $returnedCost = $this->returnedCost();

        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'customer_id' => $this->customer_id,
            'return_number' => $this->return_number,
            'return_date' => $this->return_date?->toDateString(),
            'reason' => $this->reason,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'refund_amount' => $this->refund_amount,
            'returned_cost' => $returnedCost,
            // How this particular return was actually settled: one refund
            // payment per method, the total that left the business, and the
            // remainder no method could cover, which was relieved on the
            // customer's account instead.
            //
            // `whenLoaded()` rather than a hand-rolled check: the list endpoint
            // does not eager-load the refunds, and this key must simply be absent
            // there rather than present-and-null, which a plain ternary would
            // produce.
            'settlement' => $this->when(
                $this->relationLoaded('refundPayments'),
                fn (): array => $this->settlement()
            ),
            'status' => $this->status,
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'items' => SaleReturnItemResource::collection($this->whenLoaded('saleReturnItems')),
            'refund_payments' => PaymentResource::collection($this->whenLoaded('refundPayments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * How this return was settled, in money the business actually moved.
     *
     * `refunded` is what left the business and `credited` is what was instead
     * written off the customer's account. Their sum is always `refund_amount`,
     * which is the property that makes the two figures reconcilable: a return
     * either paid out, reduced a debt, or split between the two, but is never
     * neither and never both.
     *
     * `by_method` is derived from the refund payments rather than stored, so it
     * cannot disagree with the payment rows it summarises.
     *
     * @return array<string, mixed>
     */
    private function settlement(): array
    {
        $byMethod = [];
        $refunded = '0.00';
        foreach ($this->refundPayments as $payment) {
            $byMethod[$payment->payment_method] = Money::add(
                $byMethod[$payment->payment_method] ?? '0.00',
                Money::format($payment->amount),
            );
            $refunded = Money::add($refunded, Money::format($payment->amount));
        }

        $credited = Money::subtract(Money::format($this->refund_amount), $refunded);

        return [
            'refunded' => $refunded,
            // What was relieved on the customer's account rather than paid out.
            // Zero for a return fully settled by refund payments — which is the
            // normal case for a sale that was paid.
            'credited' => Money::isNegative($credited) ? '0.00' : $credited,
            'by_method' => $byMethod,
        ];
    }

    /**
     * Cost of goods reversed by this return, or `null` when unpriceable.
     *
     * Only meaningful once `saleReturnItems` is loaded — every caller of this
     * resource is a service method that eager-loads it, so no query is issued
     * here. Each line contributes `unit_cost × quantity` read from its own
     * `sale_items` row, which is the cost that was actually incurred at the time
     * of the sale rather than the product's current `cost_price`.
     */
    private function returnedCost(): ?string
    {
        if (! $this->relationLoaded('saleReturnItems')) {
            return null;
        }

        $total = '0.00';
        foreach ($this->saleReturnItems as $item) {
            $unitCost = $item->saleItem?->unit_cost;

            // One unpriced line makes the whole figure unpublishable: reporting a
            // partial sum would silently understate the cost that came back.
            if ($unitCost === null) {
                return null;
            }

            $total = Money::add($total, Money::multiply(Money::format($unitCost), Quantity::format($item->quantity)));
        }

        return $total;
    }
}
