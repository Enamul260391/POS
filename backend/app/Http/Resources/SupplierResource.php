<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled supplier representation.
 *
 * `business_id` and `deleted_at` are deliberately absent: the tenant is known
 * from the authenticated user and the soft-delete marker is internal. Money
 * stays a decimal string through the `decimal:2` cast (never a float).
 */
class SupplierResource extends JsonResource
{
    /**
     * Transform the supplier into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'opening_balance' => $this->opening_balance,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}