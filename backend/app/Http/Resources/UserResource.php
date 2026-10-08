<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled user representation for auth responses.
 *
 * Sensitive fields (password, remember_token, token hashes) are never
 * exposed — this resource is the single chokepoint that prevents leakage.
 *
 * Besides the identity fields it carries the `capabilities` the principal
 * satisfies, so a client can decide what a signed-in user may open *before*
 * issuing the request. Without it the UI could only learn its limits by
 * provoking a `403` from every module in turn, which is both wasteful and
 * indistinguishable from a genuine outage — the very reason a partially
 * authorised screen used to show data and an error banner side by side.
 *
 * The list is resolved by `PermissionService`, the same authority the Gate
 * enforces with, so what the client is told and what the API accepts are the
 * same answer computed once.
 *
 * `business_id` is nullable and `is_platform_owner` is the explicit platform
 * identity marker: the Platform Owner carries `business_id = null` and
 * `is_platform_owner = true`, every business user carries their business id
 * and `is_platform_owner = false`. The flag is resolved through
 * {@see \App\Models\User::isPlatformOwner()} — never re-derived from
 * `business_id`, email or id — so the client and the server read the same
 * identity rule.
 */
class UserResource extends JsonResource
{
    /**
     * Transform the user into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'is_platform_owner' => $this->resource->isPlatformOwner(),
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toDateTimeString(),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('slug')->toArray();
            }),
            'capabilities' => app(PermissionService::class)->resolvedCapabilities($this->resource),
        ];
    }
}
