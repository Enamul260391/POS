<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexStockMovementRequest;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Services\Inventory\InventoryService;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory endpoints backed by the `stock_movements` ledger.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET  /api/v1/stock-movements                                (movement log)
 *  - POST /api/v1/products/{product}/stock-adjustments           (manual adjustment)
 *
 * `stock_movements` is the single source of truth for on-hand quantity: it
 * stores a signed `quantity`, so current stock is `SUM(quantity)` per product
 * and there is no mutable stock column on `products` to write.
 *
 * The adjustment path runs inside a DB transaction with a per-product row
 * lock (see {@see InventoryService::adjust()}), rejects negative balances
 * instead of clamping them, and never trusts client-supplied tenancy fields.
 */
class StockMovementController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ProductService $products,
    ) {}

    /**
     * Paginated, tenant-scoped stock movement log (newest first).
     *
     * A `product_id` that belongs to another business simply matches nothing:
     * the business scope is applied first, so the response never reveals
     * whether the product exists elsewhere.
     */
    public function index(IndexStockMovementRequest $request): JsonResponse
    {
        $paginator = $this->inventory->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Stock movements retrieved successfully', StockMovementResource::class);
    }

    /**
     * Apply a manual stock adjustment (`receive`, `remove` or `set`).
     *
     * The product is resolved inside the authenticated business first, so a
     * foreign product id yields a 404 before anything is written. The response
     * returns the created ledger row plus the balance before and after the
     * adjustment.
     */
    public function adjust(StockAdjustmentRequest $request, string $product): JsonResponse
    {
        $productModel = $this->products->find($this->businessId($request), (int) $product);

        $result = $this->inventory->adjust(
            $productModel,
            $request->user()?->getKey(),
            $request->validated(),
        );

        // Re-read after the write so the embedded product carries the
        // post-adjustment ledger balance (the pre-adjustment model is stale).
        $refreshed = $this->products->find($this->businessId($request), (int) $product);

        return $this->success([
            'product' => new ProductResource($refreshed),
            'movement' => new StockMovementResource($result['movement']),
            'stock_before' => $result['stock_before'],
            'current_stock' => $result['current_stock'],
        ], 'Stock adjusted successfully');
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
