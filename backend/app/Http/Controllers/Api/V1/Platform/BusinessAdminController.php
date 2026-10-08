<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Business\StoreBusinessAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\BusinessAdminProvisioningService;
use Illuminate\Http\JsonResponse;

/**
 * Platform-level first Business Admin provisioning endpoint.
 *
 * POST /api/v1/platform/businesses/{business}/admin
 *
 * Protected by `auth:sanctum` + `active` + `platform` and the
 * `platform.businesses.admin.create` Gate.
 *
 * Creates exactly one initial Business Admin for a business that has no
 * administrators yet. The admin role is resolved server-side (the 'admin'
 * slug for the route business) so the client cannot choose an arbitrary
 * privileged role.
 */
class BusinessAdminController extends Controller
{
    public function __construct(
        private readonly BusinessAdminProvisioningService $provisioning,
    ) {}

    /**
     * Provision the first Business Admin for the route business.
     *
     * @throws \Illuminate\Validation\ValidationException when the business already has an admin.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the business is not found.
     */
    public function store(StoreBusinessAdminRequest $request, string $business): JsonResponse
    {
        $businessId = $this->provisioning->business((int) $business)->getKey();

        $user = $this->provisioning->provisionFirstAdmin((int) $businessId, $request->userData());

        return $this->success(new UserResource($user), 'First Business Admin created successfully', 201);
    }
}