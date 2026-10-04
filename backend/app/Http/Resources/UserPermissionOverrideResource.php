<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled representation of one user permission override.
 *
 * Exposes exactly what an admin screen needs to render and edit a single
 * override: which permission it applies to (id, slug and display name, so the
 * UI never has to join against `/permissions` itself) and whether it forces the
 * permission on (`allow`) or off (`deny`).
 *
 * `business_id` is deliberately absent — the owning tenant is already known from
 * the authenticated user, and the field is pure tenancy plumbing, exactly as in
 * {@see RoleResource}. The nested permission reuses {@see PermissionResource} so
 * a permission looks identical whether it is read here, from `/roles` or from
 * `/permissions`.
 */
class UserPermissionOverrideResource extends JsonResource
{
    /**
     * Transform the override into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'permission_id' => $this->permission_id,
            'permission' => new PermissionResource($this->whenLoaded('permission')),
            'effect' => $this->effect,
        ];
    }
}
