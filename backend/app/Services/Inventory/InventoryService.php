<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Realtime\RealtimeBroadcaster;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inventory ledger: the single source of truth for on-hand quantity.
 *
 * `stock_movements` stores a **signed** `quantity` (positive = stock in,
 * negative = stock out), so current stock is `SUM(quantity)` per product and
 * there is no mutable stock column anywhere. This service is the only place
 * that writes a movement and the only place that computes a balance.
 *
 * All arithmetic goes through {@see Quantity} (fixed-point, four decimal
 * places) so a POS never accumulates floating point drift; quantities are
 * formatted into strings and never used as floats.
 */
final class InventoryService
{
    public function __construct(
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Current stock of one product, derived from the ledger.
     *
     * Uses a database aggregate (`SUM`), so no movement rows are loaded into
     * PHP. The `business_id` constraint is part of the query, which keeps the
     * balance correct even if a movement ever carried a mismatched product id.
     */
    public function currentStock(int $businessId, int $productId): string
    {
        $sum = StockMovement::query()
            ->forBusiness($businessId)
            ->forProduct($productId)
            ->sum('quantity');

        return Quantity::format($sum);
    }

    /**
     * Current stock of several products in one aggregate.
     *
     * Phase 27: the checkout path needs the balance of every distinct product
     * on the sale before it may post stock-out. Calling {@see currentStock()}
     * per product issued one `SUM(quantity)` round trip per line, so a
     * 20-line basket cost 20 queries. This groups the ledger by product in a
     * single `GROUP BY product_id` query instead.
     *
     * The returned map is keyed by product id and only contains ids that have
     * at least one ledger row; a missing entry means "no movements yet", which
     * the caller must read as a zero balance.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    public function currentStockForProducts(int $businessId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = StockMovement::query()
            ->forBusiness($businessId)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as stock')
            ->pluck('stock', 'product_id');

        $balances = [];
        foreach ($productIds as $productId) {
            $balances[$productId] = Quantity::format($rows[$productId] ?? 0);
        }

        return $balances;
    }

    /**
     * Paginated movement log for one tenant, newest first.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->forBusiness($businessId)
            // The recorder is shown in the UI, so eager load it to avoid N+1.
            ->with('creator:id,name');

        $productId = $filters['product_id'] ?? null;
        if ($productId !== null) {
            $query->forProduct((int) $productId);
        }

        $type = $filters['type'] ?? null;
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($filters));
    }

    /**
     * Append one row to the ledger.
     *
     * Internal write path shared by manual adjustments and by the opening stock
     * of a newly created product. `business_id` is taken from the product, never
     * from the caller, so a movement can never be posted into another tenant.
     *
     * @param  string  $quantity  Signed change (e.g. `"5"`, `"-2.5"`).
     */
    /**
     * Append one row to the ledger.
     *
     * Internal write path shared by manual adjustments, by the opening stock
     * of a newly created product and by purchase stock-ins. `business_id` is
     * taken from the product, never from the caller, so a movement can never
     * be posted into another tenant.
     *
     * `reference_type` / `reference_id` optionally point at the document that
     * caused the movement (e.g. `Purchase::class` + purchase id), which keeps
     * the ledger traceable back to its source; manual adjustments leave them
     * null, exactly as before.
     *
     * @param  string  $quantity  Signed change (e.g. `"5"`, `"-2.5"`).
     * @param  class-string|null  $referenceType  Morph class of the causing document, or null.
     * @param  int|null  $referenceId  Key of the causing document, or null.
     */
    public function record(
        Product $product,
        ?int $userId,
        string $type,
        string $quantity,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'business_id' => $product->business_id,
            'product_id' => $product->getKey(),
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ]);

        // Realtime announcement, here and nowhere else.
        //
        // This method is the ONLY write path to `stock_movements` — product
        // opening stock, purchase stock-in, sale stock-out, sale-return
        // restoration and manual adjustment all funnel through it. Announcing
        // the change here is what makes the coverage complete by construction:
        // a future stock-changing flow cannot be forgotten, because it cannot
        // post a movement without going through this method.
        //
        // The announcement is registered, not sent: the balance is read and the
        // event broadcast only after the enclosing transaction commits, and
        // never at all if it rolls back. See RealtimeBroadcaster::stockChanged().
        $this->realtime->stockChanged($product);

        return $movement;
    }

    /**
     * Apply a manual stock adjustment and return the resulting balance.
     *
     * `receive` adds units, `remove` takes them out and `set` overrides the
     * calculated balance with a physical count. The client always sends the
     * amount that was counted; this method converts it into the signed change
     * the ledger stores.
     *
     * Guarantees:
     *  - runs inside a transaction and locks the product row, so two concurrent
     *    adjustments cannot both act on the same stale balance;
     *  - never produces negative stock — a removal larger than the balance is
     *    rejected with a 409 instead of being clamped to zero;
     *  - a no-op recount (setting the count it already has) is rejected, so the
     *    ledger stays free of meaningless rows;
     *  - products that do not track stock (`unit = service`) are refused.
     *
     * @param  array<string, mixed>  $data  Validated adjustment payload.
     * @return array{movement: StockMovement, stock_before: string, current_stock: string}
     *
     * @throws BusinessRuleException when the adjustment is not allowed.
     * @throws InvalidArgumentException when the movement type is unknown.
     */
    public function adjust(Product $product, ?int $userId, array $data): array
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, StockMovement::ADJUSTMENT_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported stock movement type [{$type}].");
        }

        if (! $product->tracksStock()) {
            throw new BusinessRuleException(sprintf('%s does not track stock.', $product->name));
        }

        $quantity = Quantity::format($data['quantity'] ?? 0);
        $note = $data['note'] ?? null;

        return DB::transaction(function () use ($product, $userId, $type, $quantity, $note): array {
            // Serialise adjustments per product: the row lock makes the
            // read-check-write sequence atomic on MySQL (no-op on SQLite).
            Product::query()->whereKey($product->getKey())->lockForUpdate()->first();

            $stockBefore = $this->currentStock($product->business_id, $product->getKey());

            $delta = match ($type) {
                StockMovement::TYPE_RECEIVE => $quantity,
                StockMovement::TYPE_REMOVE => Quantity::negate($quantity),
                // A recount stores the difference between counted and calculated.
                StockMovement::TYPE_SET => Quantity::subtract($quantity, $stockBefore),
            };

            if (Quantity::isZero($delta)) {
                throw new BusinessRuleException(sprintf('Stock is already %s.', $stockBefore));
            }

            if (Quantity::isNegative(Quantity::add($stockBefore, $delta))) {
                throw new BusinessRuleException(sprintf(
                    'Only %s on hand — cannot remove %s.',
                    $stockBefore,
                    $quantity,
                ));
            }

            $movement = $this->record($product, $userId, $type, $delta, $note);

            return [
                'movement' => $movement,
                'stock_before' => $stockBefore,
                // Re-read from the ledger inside the transaction so the client
                // always receives the persisted balance.
                'current_stock' => $this->currentStock($product->business_id, $product->getKey()),
            ];
        });
    }

    /**
     * Page size, clamped to the documented maximum.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
