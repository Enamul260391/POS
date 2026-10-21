<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product CRUD endpoints.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET    /api/v1/products
 *  - POST   /api/v1/products
 *  - GET    /api/v1/products/{product}
 *  - PUT    /api/v1/products/{product}
 *  - PATCH  /api/v1/products/{product}
 *  - DELETE /api/v1/products/{product}
 *
 * Products carry master data only — there is no stock field in the contract.
 * On-hand quantity is derived from the `stock_movements` ledger and is written
 * exclusively through the inventory endpoints
 * (`POST /api/v1/products/{product}/stock-adjustments`).
 *
 * Route parameters are raw ids resolved through
 * {@see ProductService::find()} with the authenticated business, so another
 * tenant's product is indistinguishable from a missing one (404).
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    /**
     * Paginated, tenant-scoped product list (search, category/status/sku/
     * barcode filters, whitelisted sorting, ledger-derived current stock).
     */
    public function index(IndexProductRequest $request): JsonResponse
    {
        $paginator = $this->products->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Products retrieved successfully', ProductResource::class);
    }

    /**
     * Create a product for the authenticated user's business.
     *
     * Master data only. The request carries no stock: on-hand quantity is
     * never posted on a product, and `opening_stock` is rejected outright —
     * stock enters the system through the Purchase workflow.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create(
            $this->businessId($request),
            $request->user()?->getKey(),
            $request->validated(),
        );

        return $this->success(new ProductResource($product), 'Product created successfully', 201);
    }

    /**
     * Fetch one product inside the authenticated business, including its
     * ledger-derived current stock and category.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        $found = $this->products->find($this->businessId($request), (int) $product);

        return $this->success(new ProductResource($found), 'Product retrieved successfully');
    }

    /**
     * Update the editable master data of a product (PUT and PATCH).
     *
     * `business_id` and stock are not part of the validated payload, so a
     * client posting them changes nothing — inventory moves only through
     * stock movements.
     */
    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        $found = $this->products->find($this->businessId($request), (int) $product);

        $updated = $this->products->update($found, $request->validated());

        return $this->success(new ProductResource($updated), 'Product updated successfully');
    }

    /**
     * Soft-delete a product.
     *
     * Soft deletion keeps `stock_movements`, `sale_items`, `purchase_items` and
     * `sale_return_items` intact, so historical business data is never
     * destroyed.
     */
    public function destroy(Request $request, string $product): JsonResponse
    {
        $found = $this->products->find($this->businessId($request), (int) $product);

        $this->products->delete($found);

        return $this->success(null, 'Product deleted successfully');
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
