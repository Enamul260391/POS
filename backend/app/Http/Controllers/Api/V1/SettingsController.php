<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business settings endpoints.
 *
 * Endpoints (behind `auth:sanctum` + a `can:` permission check):
 *  - GET   /api/v1/settings   requires `settings.view`
 *  - PUT   /api/v1/settings   requires `settings.update`
 *  - PATCH /api/v1/settings   requires `settings.update`
 *
 * There is no `{business}` or `{setting}` in the path on purpose: the settings
 * being operated on are always those of the authenticated user's business, so a
 * client can never address another tenant's configuration.
 *
 * PUT and PATCH share one implementation because the stored shape is a key/value
 * map: both upsert the supplied keys and leave unmentioned keys untouched.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Return the current business's settings.
     *
     * Credential-shaped keys are filtered out by the service, so no secret can
     * reach the response even if such a row exists in the table.
     */
    public function show(Request $request): JsonResponse
    {
        $settings = $this->settings->all($this->businessId($request));

        return $this->success(
            SettingsResource::collection($settings),
            'Settings retrieved successfully',
        );
    }

    /**
     * Upsert the supplied settings for the current business (PUT and PATCH).
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings->update(
            $this->businessId($request),
            $request->settings(),
        );

        return $this->success(
            SettingsResource::collection($settings),
            'Settings updated successfully',
        );
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
