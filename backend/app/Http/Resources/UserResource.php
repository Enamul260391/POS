<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled user representation for auth responses.
 *
 * Sensitive fields (password, remember_token, token hashes) are never
 * exposed — this resource is the single chokepoint that prevents leakage.
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
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toDateTimeString(),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('slug')->toArray();
            }),
        ];
    }
}
