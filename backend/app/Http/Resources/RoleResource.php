<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled role representation.
 *
 * `business_id` is deliberately absent: the owning tenant is already known from
 * the authenticated user, and the field is pure tenancy plumbing. `permissions`
 * is emitted only when the relation was eager-loaded (every role endpoint
 * eager-loads it, which keeps the list free of N+1 queries), and the nested
 * objects reuse {@see PermissionResource} so a permission looks identical
 * whether it is read from `/roles` or `/permissions`.
 */
class RoleResource extends JsonResource
{
    /**
     * Transform the role into an array.
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
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
