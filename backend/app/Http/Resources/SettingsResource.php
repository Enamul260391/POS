<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicitly controlled business-setting representation.
 *
 * Only the `key`/`value` pair is exposed: `business_id` is tenancy plumbing
 * (already known from the authenticated user) and the timestamps are internal.
 *
 * Credential-shaped keys are filtered out upstream by `SettingsService`, so a
 * secret that happens to exist in the table can never reach a response through
 * this resource.
 */
class SettingsResource extends JsonResource
{
    /**
     * Transform one setting into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
        ];
    }
}
