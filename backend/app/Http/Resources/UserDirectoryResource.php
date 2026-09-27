<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff-directory representation of a user.
 *
 * Deliberately separate from {@see UserResource}, which serves the auth
 * endpoints: that one is a principal (id, name, email, role **slugs**) and must
 * not grow management fields, while this one is a directory row. Keeping them
 * apart means adding a management column can never leak into `/auth/me`.
 *
 * `role` is a single object rather than a collection because the management
 * screen edits one role at a time. `roles` is still emitted so a client can see
 * that the underlying relation is many-to-many.
 */
class UserDirectoryResource extends JsonResource
{
    /**
     * Transform the user into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryRole = $this->whenLoaded('roles', fn () => $this->roles->first());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'branch' => $this->branch,
            'status' => $this->status,
            'notes' => $this->notes,
            'role' => $primaryRole === null ? null : [
                'id' => $primaryRole->id,
                'name' => $primaryRole->name,
                'slug' => $primaryRole->slug,
            ],
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->map(static fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}