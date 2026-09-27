<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled purchase representation.
 *
 * All money fields are the **server-calculated** totals stored by the service
 * (`decimal:2` cast, never floats) — the client never supplies them. The
 * purchase-side payment picture (`paid_amount`, `due_amount`,
 * `payment_status`) is part of the `purchases` row itself; payment documents
 * belong to a later phase and are not exposed here.
 *
 * `business_id`, `deleted_at`-style internals and `created_by`-adjacent
 * bookkeeping stay hidden. `supplier`, `items` (with their products) and
 * `creator` appear only when the query eager loaded them, so the list view
 * stays free of N+1 queries.
 */
class PurchaseResource extends JsonResource
{
    /**
     * Transform the purchase into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'invoice_number' => $this->invoice_number,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'grand_total' => $this->grand_total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,
            'payment_status' => $this->payment_status,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'items' => PurchaseItemResource::collection($this->whenLoaded('purchaseItems')),
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}