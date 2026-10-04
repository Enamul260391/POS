<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled sale payment representation.
 *
 * `type` and `sale_return_id` are what separate an inbound payment from an
 * outbound refund. Both are exposed because a client reconciling a return
 * against its settlement cannot do it correctly without them: it needs to know
 * which rows are refunds, and which return each belongs to.
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'purchase_id' => $this->purchase_id,
            // Non-null only for a refund, and then `sale_return_id` is set too.
            // Together they say unambiguously whether this row is money in or
            // money out, which `payment_method` on its own never could.
            'type' => $this->type,
            'sale_return_id' => $this->sale_return_id,
            'payment_method' => $this->payment_method,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'received_by' => $this->received_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
