<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled business representation for the platform API.
 *
 * Only tenant-identifying master data and status are exposed: the `settings`
 * JSON blob is tenant internals and never leaves the server here, and the
 * soft-delete marker is an internal concern. `users_count` is included
 * whenever the query counted it (every platform business endpoint does), so
 * the Platform Owner can see at a glance whether a tenant is in use.
 */
class BusinessResource extends JsonResource
{
    /**
     * Transform the business into an array.
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
            'logo' => $this->logo,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'users_count' => $this->whenCounted('users'),
            // Does this business already have an administrator? Published from
            // the same relation the first-admin provisioning guard uses, so the
            // UI can offer the first-admin setup exactly when the server would
            // accept it. The key is omitted when the count was not loaded, so a
            // client never reads "unknown" as "no admin yet".
            'has_admin' => $this->when(
                isset($this->resource->admin_users_count),
                (bool) $this->resource->admin_users_count,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
