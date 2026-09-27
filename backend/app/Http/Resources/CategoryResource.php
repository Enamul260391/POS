<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled category representation.
 *
 * `business_id` and `deleted_at` are deliberately absent: the tenant is already
 * known from the authenticated user, and the soft-delete marker is an internal
 * concern. `products_count` is included whenever the query counted it (every
 * category endpoint in Phase 20 does).
 */
class CategoryResource extends JsonResource
{
    /**
     * Transform the category into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
