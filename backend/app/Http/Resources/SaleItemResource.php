<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled sale line representation.
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'product_id' => $this->product_id,
            'batch_id' => $this->batch_id,
            'batch' => $this->whenLoaded('batch', function (): array {
                return [
                    'id' => $this->batch->id,
                    'batch_number' => $this->batch->batch_number,
                    'selling_price' => $this->batch->selling_price,
                    'mrp' => $this->batch->mrp,
                ];
            }),
            'quantity' => Quantity::format($this->quantity),
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
