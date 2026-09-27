<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled stock movement (ledger row) representation.
 *
 * `business_id` is deliberately absent (the tenant is known from the
 * authenticated user). `quantity` is the **signed** ledger change — positive
 * adds units, negative removes them — normalised to a clean decimal string so
 * `5`, not `5.0000`, reaches the client.
 *
 * `creator` is only present when the query eager loaded it, and exposes just
 * id + name: an audit trail needs the recorder's identity, not their account.
 */
class StockMovementResource extends JsonResource
{
    /**
     * Transform the stock movement into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'type' => $this->type,
            'quantity' => Quantity::format($this->quantity),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
