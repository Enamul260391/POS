<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled sale-return line representation.
 */
class SaleReturnItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The cost this line reverses is read through `sale_item_id`, whose
        // `unit_cost` is the snapshot taken when the sale was written. It is
        // exposed rather than recomputed from the product so a consumer can never
        // accidentally value the reversal at today's `cost_price`.
        //
        // `null` means the invoice line was recorded without a cost (a service
        // line, or a row predating the column). That is reported as-is: no
        // historical cost is invented, and a report that needs it keeps
        // withholding the figure as it already does for such sales.
        $unitCost = $this->saleItem?->unit_cost;

        return [
            'id' => $this->id,
            'sale_item_id' => $this->sale_item_id,
            'product_id' => $this->product_id,
            'quantity' => Quantity::format($this->quantity),
            'unit_price' => $this->unit_price,
            'total' => $this->total,
            'unit_cost' => $unitCost,
            // The cost of goods this line puts back: `unit_cost × quantity`,
            // `null` whenever the cost is unknown rather than `0.00`.
            'returned_cost' => $unitCost === null
                ? null
                : Money::multiply(Money::format($unitCost), Quantity::format($this->quantity)),
            'product' => ProductResource::make($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
