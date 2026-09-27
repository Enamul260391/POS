<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled permission representation.
 *
 * The catalogue is global, so there is no tenant field to hide. `roles_count`
 * appears only when the query counted it — the list endpoint does, which avoids
 * an N+1 when a client renders "used by N roles".
 */
class PermissionResource extends JsonResource
{
    /**
     * Transform the permission into an array.
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
            'roles_count' => $this->whenCounted('roles'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
