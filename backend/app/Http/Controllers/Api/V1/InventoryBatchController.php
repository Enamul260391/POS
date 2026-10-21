<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexInventoryBatchRequest;
use App\Http\Resources\InventoryBatchResource;
use App\Models\Product;
use App\Services\InventoryBatch\InventoryBatchService;
use App\Support\ApiResponse;
use App\Support\Money;
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
     * STEP 4 POS price options for one product, on demand.
     *
     * Returns exactly one option per distinct selling price among eligible
     * batches (`remaining_quantity > 0`, FIFO `received_date ASC, id ASC`).
     * The frontend fetches this per product being added — never the full
     * catalogue. An empty list means zero eligible stock: the cart addition
     * must be blocked.
     */
    public function priceOptions(Request $request, string $product): JsonResponse
    {
        $businessId = $this->businessId($request);

        $row = Product::query()
            ->forBusiness($businessId)
            ->whereKey((int) $product)
            ->firstOrFail();

        $eligible = $this->batches->eligibleForProduct($businessId, (int) $row->getKey());
        $options = $this->batches->priceOptions($eligible, Money::format($row->selling_price));

        return ApiResponse::success([
            'product_id' => (int) $row->getKey(),
            'options' => $options,
        ], 'Batch price options retrieved successfully');
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
