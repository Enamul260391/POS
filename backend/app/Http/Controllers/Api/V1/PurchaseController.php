<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\IndexPurchaseRequest;
use App\Http\Requests\Purchase\StorePurchaseRequest;
use App\Http\Requests\Purchase\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Services\Purchase\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase endpoints.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET    /api/v1/purchases
 *  - POST   /api/v1/purchases
 *  - GET    /api/v1/purchases/{purchase}
 *  - PUT    /api/v1/purchases/{purchase}
 *  - PATCH  /api/v1/purchases/{purchase}
 *  - DELETE /api/v1/purchases/{purchase}
 *
 * Thin by design: Form Requests validate, PurchaseService owns total
 * calculation, the transaction and the stock-in through the existing
 * inventory ledger, and the standard envelope comes from the
 * {@see ApiResponse} trait.
 *
 * Route parameters are raw ids resolved through
 * {@see PurchaseService::find()} with the authenticated business, so another
 * tenant's purchase is indistinguishable from a missing one (404). Totals
 * and stock are never taken from the client.
 */
class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
    ) {}

    /**
     * Paginated, tenant-scoped purchase list (search, supplier, status,
     * payment status, purchase-date range, whitelisted sorting).
     */
    public function index(IndexPurchaseRequest $request): JsonResponse
    {
        $paginator = $this->purchases->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Purchases retrieved successfully', PurchaseResource::class);
    }

    /**
     * Create a purchase with its items and stock-in movements atomically.
     *
     * Totals are calculated server-side; every stock increase is appended to
     * the `stock_movements` ledger inside one DB transaction — nothing is
     * trusted from the client except quantities, prices and references.
     */
    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create(
            $this->businessId($request),
            $request->user()?->getKey(),
            $request->validated(),
        );

        return $this->success(new PurchaseResource($purchase), 'Purchase created successfully', 201);
    }

    /**
     * Fetch one purchase inside the authenticated business, including its
     * supplier, items (with products and ledger stock) and creator.
     */
    public function show(Request $request, string $purchase): JsonResponse
    {
        $found = $this->purchases->find($this->businessId($request), (int) $purchase);

        return $this->success(new PurchaseResource($found), 'Purchase retrieved successfully');
    }

    /**
     * Update the safe metadata of a purchase (PUT and PATCH).
     *
     * Items and totals are finalized once stock has been posted: attempts to
     * rewrite them produce a controlled 409 from the service, never a silent
     * ledger desynchronization.
     */
    public function update(UpdatePurchaseRequest $request, string $purchase): JsonResponse
    {
        $found = $this->purchases->find($this->businessId($request), (int) $purchase);

        $updated = $this->purchases->update($found, $request->validated());

        return $this->success(new PurchaseResource($updated), 'Purchase updated successfully');
    }

    /**
     * Delete a purchase only when it never affected inventory and has no
     * payments; otherwise a controlled 409 keeps the ledger consistent.
     */
    public function destroy(Request $request, string $purchase): JsonResponse
    {
        $found = $this->purchases->find($this->businessId($request), (int) $purchase);

        $this->purchases->delete($found);

        return $this->success(null, 'Purchase deleted successfully');
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