<?php

declare(strict_types=1);

namespace App\Services\InventoryBatch;

use App\Models\InventoryBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only service for inventory batches.
 *
 * Batches are an allocation layer on top of `stock_movements`; they never
 * replace the ledger as the source of truth for on-hand quantity.
 */
final class InventoryBatchService
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Paginated, tenant-scoped batch list, newest first.
     *
     * @param  array{product_id: int|null, per_page: int}  $filters
     * @return LengthAwarePaginator<int, InventoryBatch>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = InventoryBatch::query()
            ->forBusiness($businessId)
            ->with(['product:id,name,sku', 'purchaseItem:id,purchase_id,product_id,quantity,unit_cost'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $productId = $filters['product_id'] ?? null;
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query->paginate($filters['per_page']);
    }
}
