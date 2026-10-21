<?php

declare(strict_types=1);

namespace App\Services\InventoryBatch;

use App\Exceptions\BusinessRuleException;
use App\Models\InventoryBatch;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Service for inventory batches.
 *
 * Batches are an allocation layer on top of `stock_movements`; they never
 * replace the ledger as the source of truth for on-hand quantity.
 *
 * `remaining_quantity` is that allocation: it starts at `received_quantity`
 * and is moved by the sale flow (units leave the batch) and the sale-return
 * flow (units come back to the very same batch). Both directions take the
 * batch row with `lockForUpdate()` so two concurrent sales of the same batch
 * cannot both spend the same units.
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

    /**
     * Eligible batches for POS selection, FIFO ordered.
     *
     * STEP 4: eligibility is `remaining_quantity > 0`, ordered
     * `received_date ASC, id ASC`. No expiry filtering (no FEFO).
     *
     * @return Collection<int, InventoryBatch>
     */
    public function eligibleForProduct(int $businessId, int $productId): Collection
    {
        return InventoryBatch::query()
            ->forBusiness($businessId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Distinct selling-price options for the POS selector.
     *
     * One option per distinct selling price. Each option represents the
     * oldest eligible FIFO batch at that price. Batches with a null
     * selling price are grouped under the product's current selling price
     * fallback supplied by the caller.
     *
     * @param  Collection<int, InventoryBatch>  $eligible  FIFO-ordered eligible batches.
     * @return array<int, array{selling_price: string, batch_id: int, batch_number: string, available_quantity: string}>
     */
    public function priceOptions(Collection $eligible, ?string $fallbackSellingPrice = null): array
    {
        $options = [];

        foreach ($eligible as $batch) {
            $price = $batch->selling_price !== null
                ? Money::format($batch->selling_price)
                : ($fallbackSellingPrice !== null ? Money::format($fallbackSellingPrice) : null);

            if ($price === null) {
                continue;
            }

            if (! array_key_exists($price, $options)) {
                $options[$price] = [
                    'selling_price' => $price,
                    'batch_id' => (int) $batch->getKey(),
                    'batch_number' => (string) $batch->batch_number,
                    'available_quantity' => Quantity::format($batch->remaining_quantity),
                ];
            } else {
                $options[$price]['available_quantity'] = Quantity::add(
                    $options[$price]['available_quantity'],
                    Quantity::format($batch->remaining_quantity),
                );
            }
        }

        return array_values($options);
    }

    /**
     * Whether any batch history exists for the product (including depleted).
     */
    public function hasHistory(int $businessId, int $productId): bool
    {
        return InventoryBatch::query()
            ->forBusiness($businessId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Allocate quantity FIFO within one selling price.
     *
     * Locks every eligible batch row at that price in FIFO order, then
     * consumes them oldest-first. Never touches another price.
     *
     * @return array<int, array{batch: InventoryBatch, quantity: string}>
     *
     * @throws BusinessRuleException with the exact STEP 4 shortfall message.
     */
    public function allocateAtPrice(
        int $businessId,
        int $productId,
        string $sellingPrice,
        string $quantity,
        ?string $fallbackSellingPrice = null,
    ): array {
        $wanted = Money::format($sellingPrice);
        $fallback = $fallbackSellingPrice !== null ? Money::format($fallbackSellingPrice) : null;

        $candidates = InventoryBatch::query()
            ->forBusiness($businessId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('received_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(function (InventoryBatch $batch) use ($wanted, $fallback): bool {
                $price = $batch->selling_price !== null ? Money::format($batch->selling_price) : $fallback;

                return $price !== null && Money::equals($price, $wanted);
            })
            ->values();

        if ($candidates->isEmpty()) {
            throw new BusinessRuleException('please add another batch of this product', 409);
        }

        $remaining = Quantity::format($quantity);
        $allocations = [];

        foreach ($candidates as $batch) {
            if (Quantity::isZero($remaining) || Quantity::isNegative($remaining)) {
                break;
            }

            $available = Quantity::format($batch->remaining_quantity);
            $take = Quantity::isNegative(Quantity::subtract($available, $remaining)) ? $available : $remaining;

            $batch->remaining_quantity = Quantity::subtract($available, $take);
            $batch->save();

            $allocations[] = ['batch' => $batch->fresh(), 'quantity' => $take];
            $remaining = Quantity::subtract($remaining, $take);
        }

        if (Quantity::isPositive($remaining)) {
            throw new BusinessRuleException('please add another batch of this product', 409);
        }

        return $allocations;
    }

    /**
     * Take `quantity` units out of a batch, for a sale.
     *
     * The batch row is locked for the duration of the call and the new
     * `remaining_quantity` is written from the value that was read under that
     * lock, so two concurrent sales of the same batch serialise instead of
     * both spending the same units.
     *
     * The batch is resolved through the tenant scope and checked against the
     * product the units are sold from, so a batch of another business — or of
     * another product — can never be spent by this sale.
     *
     * @param  string  $quantity  How many units leave the batch.
     *
     * @throws BusinessRuleException when the batch does not hold that many units.
     */
    public function allocate(int $businessId, int $productId, int $batchId, string $quantity): InventoryBatch
    {
        $batch = InventoryBatch::query()
            ->forBusiness($businessId)
            ->whereKey($batchId)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            throw new BusinessRuleException('The selected batch does not belong to your business.');
        }

        if ((int) $batch->product_id !== $productId) {
            throw new BusinessRuleException('The selected batch does not belong to the product of this sale line.');
        }

        $remaining = Quantity::format($batch->remaining_quantity);
        $left = Quantity::subtract($remaining, $quantity);

        if (Quantity::isNegative($left)) {
            throw new BusinessRuleException(sprintf(
                'Insufficient quantity in batch %s: only %s remaining.',
                $batch->batch_number,
                $remaining,
            ));
        }

        $batch->remaining_quantity = $left;
        $batch->save();

        return $batch;
    }

    /**
     * Put `quantity` units back into the batch they were sold from.
     *
     * A return restores the batch the original sale line was taken from, so
     * the units land back in the lot they came out of rather than in a
     * generic "restored" pool. The row is locked for the same reason as in
     * {@see self::allocate()}.
     *
     * @param  string  $quantity  How many units come back to the batch.
     */
    public function restore(int $businessId, int $batchId, string $quantity): InventoryBatch
    {
        $batch = InventoryBatch::query()
            ->forBusiness($businessId)
            ->whereKey($batchId)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            throw new BusinessRuleException('The batch of the returned sale line no longer exists.');
        }

        $batch->remaining_quantity = Quantity::add(
            Quantity::format($batch->remaining_quantity),
            $quantity,
        );
        $batch->save();

        return $batch;
    }
}
