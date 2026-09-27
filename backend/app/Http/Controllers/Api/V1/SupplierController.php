<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\IndexSupplierRequest;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Services\Supplier\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier CRUD endpoints.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET    /api/v1/suppliers
 *  - POST   /api/v1/suppliers
 *  - GET    /api/v1/suppliers/{supplier}
 *  - PUT    /api/v1/suppliers/{supplier}
 *  - PATCH  /api/v1/suppliers/{supplier}
 *  - DELETE /api/v1/suppliers/{supplier}
 *
 * Thin by design: Form Requests validate, SupplierService owns the business
 * logic and tenant scoping, and the standard envelope comes from the
 * {@see ApiResponse} trait.
 *
 * Route parameters are raw ids resolved through
 * {@see SupplierService::find()} with the authenticated business, so another
 * tenant's supplier is indistinguishable from a missing one (404).
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
    ) {}

    /**
     * Paginated, tenant-scoped supplier list (search, status filter, sorting).
     */
    public function index(IndexSupplierRequest $request): JsonResponse
    {
        $paginator = $this->suppliers->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Suppliers retrieved successfully', SupplierResource::class);
    }

    /**
     * Create a supplier for the authenticated user's business.
     *
     * `business_id` is never read from the payload.
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->suppliers->create(
            $this->businessId($request),
            $request->validated(),
        );

        return $this->success(new SupplierResource($supplier), 'Supplier created successfully', 201);
    }

    /**
     * Fetch one supplier inside the authenticated business.
     */
    public function show(Request $request, string $supplier): JsonResponse
    {
        $found = $this->suppliers->find($this->businessId($request), (int) $supplier);

        return $this->success(new SupplierResource($found), 'Supplier retrieved successfully');
    }

    /**
     * Update the editable master data of a supplier (PUT and PATCH).
     *
     * `business_id` is not part of the validated payload, so posting it
     * changes nothing.
     */
    public function update(UpdateSupplierRequest $request, string $supplier): JsonResponse
    {
        $found = $this->suppliers->find($this->businessId($request), (int) $supplier);

        $updated = $this->suppliers->update($found, $request->validated());

        return $this->success(new SupplierResource($updated), 'Supplier updated successfully');
    }

    /**
     * Soft-delete a supplier that no purchase references.
     *
     * A supplier still in use produces a 409 business error from the service
     * (rendered by the API exception handler); purchase history is never
     * destroyed.
     */
    public function destroy(Request $request, string $supplier): JsonResponse
    {
        $found = $this->suppliers->find($this->businessId($request), (int) $supplier);

        $this->suppliers->delete($found);

        return $this->success(null, 'Supplier deleted successfully');
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload or the query string.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}