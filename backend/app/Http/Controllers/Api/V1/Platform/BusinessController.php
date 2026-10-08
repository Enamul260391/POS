<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Business\IndexBusinessRequest;
use App\Http\Requests\Platform\Business\StoreBusinessRequest;
use App\Http\Requests\Platform\Business\UpdateBusinessRequest;
use App\Http\Requests\Platform\Business\UpdateBusinessStatusRequest;
use App\Http\Resources\BusinessResource;
use App\Services\Platform\BusinessService;
use Illuminate\Http\JsonResponse;

/**
 * Platform business (tenant) management endpoints.
 *
 * Endpoints (all behind `auth:sanctum` + `active` + `platform`):
 *  - GET   /api/v1/platform/businesses                 requires `platform.businesses.view`
 *  - POST  /api/v1/platform/businesses                 requires `platform.businesses.create`
 *  - GET   /api/v1/platform/businesses/{business}      requires `platform.businesses.view`
 *  - PUT   /api/v1/platform/businesses/{business}      requires `platform.businesses.update`
 *  - PATCH /api/v1/platform/businesses/{business}      requires `platform.businesses.update`
 *  - PATCH /api/v1/platform/businesses/{business}/status requires `platform.businesses.status`
 *  - DELETE /api/v1/platform/businesses/{business}    requires `platform.businesses.delete`
 *
 * Thin by design: Form Requests validate, BusinessService owns the platform
 * logic, and the standard envelope comes from the ApiResponse trait.
 *
 * Route parameters are deliberately handled as raw ids (no implicit route
 * model binding): every lookup goes through {@see BusinessService::find()},
 * so a missing or deleted business produces a plain 404 envelope — matching
 * the tenant controllers' convention.
 *
 * This controller never reads the authenticated user's `business_id`: the
 * Platform Owner intentionally has none, and platform access is
 * cross-business by design. Tenant isolation for business users is enforced
 * one layer up, by the `platform` middleware refusing them before any
 * controller runs.
 */
class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessService $businesses,
    ) {}

    /**
     * Paginated platform-wide business list (search, status filter, sorting).
     */
    public function index(IndexBusinessRequest $request): JsonResponse
    {
        $paginator = $this->businesses->paginate($request->filters());

        return $this->paginated($paginator, 'Businesses retrieved successfully', BusinessResource::class);
    }

    /**
     * Create a business as a separate, empty tenant.
     *
     * The Platform Owner is never assigned to the new business and keeps
     * `business_id = NULL`; no tenant user or role is created.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $business = $this->businesses->create($request->validated());

        return $this->success(new BusinessResource($business), 'Business created successfully', 201);
    }

    /**
     * Fetch one business by id.
     */
    public function show(string $business): JsonResponse
    {
        $found = $this->businesses->find((int) $business);

        return $this->success(new BusinessResource($found), 'Business retrieved successfully');
    }

    /**
     * Update the editable master data of a business (PUT and PATCH).
     */
    public function update(UpdateBusinessRequest $request, string $business): JsonResponse
    {
        $found = $this->businesses->find((int) $business);

        $updated = $this->businesses->update($found, $request->validated());

        return $this->success(new BusinessResource($updated), 'Business updated successfully');
    }

    /**
     * Activate or deactivate a business (PATCH .../status).
     */
    public function updateStatus(UpdateBusinessStatusRequest $request, string $business): JsonResponse
    {
        $found = $this->businesses->find((int) $business);

        $updated = $this->businesses->setStatus($found, (string) $request->validated()['status']);

        return $this->success(new BusinessResource($updated), 'Business status updated successfully');
    }

    /**
     * Soft-delete a business that holds no tenant data.
     *
     * A business still in use produces a 409 business error from the service
     * (rendered by the API exception handler), never a silent cascade.
     */
    public function destroy(string $business): JsonResponse
    {
        $found = $this->businesses->find((int) $business);

        $this->businesses->delete($found);

        return $this->success(null, 'Business deleted successfully');
    }
}
