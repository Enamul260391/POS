<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            'status' => $this->status,
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'items' => SaleReturnItemResource::collection($this->whenLoaded('saleReturnItems')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
