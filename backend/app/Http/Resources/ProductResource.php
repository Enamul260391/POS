<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled product representation.
 *
 * `business_id` and `deleted_at` are deliberately absent: the tenant is known
 * from the authenticated user and the soft-delete marker is internal.
 *
 * `current_stock` comes from the ledger aggregate selected by
 * {@see Product::scopeWithCurrentStock()} (`SUM(stock_movements.quantity)`)
 * — never from a column on `products`, because none exists. It is `null` when
 * the query did not select the aggregate, so the resource can never report a
 * made-up balance. Money stays a decimal string (never a float).
 *
 * `category` is only present when the relation was eager loaded, and always
 * through {@see CategoryResource}, which exposes controlled fields only.
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the product into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit' => $this->unit,
            'low_stock_threshold' => $this->low_stock_threshold,
            'image' => $this->image,
            'status' => $this->status,
            'current_stock' => $this->resource->hasAttribute('current_stock')
                ? Quantity::format($this->resource->getAttribute('current_stock'))
                : null,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
