<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled purchase line representation.
 *
 * `quantity` is normalised through {@see Quantity} (`5`, not `5.0000`) while
 * money columns keep their `decimal:2` cast — the same convention the rest of
 * the API uses. `product` is only present when the relation was eager loaded
 * (the purchase detail endpoint loads it with ledger-derived stock), so the
 * resource can never trigger an N+1 on its own.
 */
class PurchaseItemResource extends JsonResource
{
    /**
     * Transform the purchase item into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_id' => $this->purchase_id,
            'product_id' => $this->product_id,
            'quantity' => Quantity::format($this->quantity),
            'unit_cost' => $this->unit_cost,
            'discount' => $this->discount,
            'mrp' => $this->mrp,
            'selling_price' => $this->selling_price,
            'selling_code' => $this->selling_code,
            'tax' => $this->tax,
            'total' => $this->total,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}