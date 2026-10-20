<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexInventoryBatchRequest;
use App\Http\Resources\InventoryBatchResource;
use App\Services\InventoryBatch\InventoryBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only inventory batch endpoints.
 *
 * Batches are an allocation layer on top of `stock_movements`; they are
 * created by the purchase flow and consumed by the sale/return flow. This
 * controller only exposes the list — create/update/delete are not part of
 * STEP 2.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET /api/v1/inventory-batches
 *
 * Tenant scope comes from the authenticated user; client `business_id` is
 * never trusted.
 */
class InventoryBatchController extends Controller
{
    public function __construct(
        private readonly InventoryBatchService $batches,
    ) {}

    /**
     * Paginated, tenant-scoped batch list with optional product filter.
     */
    public function index(IndexInventoryBatchRequest $request): JsonResponse
    {
        $paginator = $this->batches->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated(
            $paginator,
            'Inventory batches retrieved successfully',
            InventoryBatchResource::class,
        );
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
