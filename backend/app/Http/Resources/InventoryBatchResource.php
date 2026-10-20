<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled inventory batch representation.
 *
 * Quantities are normalised through {@see Quantity} (`5`, not `5.0000`) while
 * money columns keep their `decimal:2` cast. The batch never exposes a stock
 * balance that competes with `stock_movements` — `remaining_quantity` is an
 * allocation that the sale-side flow (STEP 3) will maintain.
 */
class InventoryBatchResource extends JsonResource
{
    /**
     * Transform the batch into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'product_id' => $this->product_id,
            'received_date' => $this->received_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'received_quantity' => Quantity::format($this->received_quantity),
            'remaining_quantity' => Quantity::format($this->remaining_quantity),
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'mrp' => $this->mrp,
            'selling_code' => $this->selling_code,
            'product' => $this->whenLoaded('product', fn (): array => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ]),
            'purchase_item' => $this->whenLoaded('purchaseItem', fn (): array => [
                'id' => $this->purchaseItem->id,
                'purchase_id' => $this->purchaseItem->purchase_id,
                'quantity' => Quantity::format($this->purchaseItem->quantity),
                'unit_cost' => $this->purchaseItem->unit_cost,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
