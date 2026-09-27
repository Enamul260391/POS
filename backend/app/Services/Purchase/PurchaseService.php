<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for supplier purchases.
 *
 * Responsibilities:
 *  - tenant-scoped resolution (a foreign purchase is simply "not found");
 *  - server-side total calculation — client-supplied totals are never trusted;
 *  - posting the stock-in through {@see InventoryService} (the one inventory
 *    implementation) inside a single DB transaction, so a purchase, its items
 *    and its stock movements succeed or roll back together;
 *  - protecting the ledger: once a purchase has posted movements, its items
 *    and totals are final (Option B editing) and it cannot be deleted, so
 *    `stock_movements` stays consistent and no orphan rows are ever left.
 *
 * `stock_movements` remains the inventory source of truth: this service never
 * touches a product stock field (none exists) and only ever appends ledger
 * rows through the inventory service.
 */
final class PurchaseService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = [
        'purchase_date', 'invoice_number', 'subtotal', 'discount', 'tax',
        'grand_total', 'paid_amount', 'due_amount', 'payment_status',
        'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'created_at';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** Message for attempts to rewrite a finalized (inventory-affecting) purchase. */
    public const FINALIZED_MESSAGE = 'Purchase items and totals cannot be modified after creation.';

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Paginated, tenant-scoped purchase list.
     *
     * Search, supplier/status/payment-status/date filters all run in SQL; the
     * supplier relation is eager loaded (no N+1). A `supplier_id` from another
     * business simply matches nothing — the business scope is applied first,
     * so the response never reveals whether it exists elsewhere.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Purchase>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = Purchase::query()
            ->forBusiness($businessId)
            ->with('supplier');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('invoice_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('supplier', static function (Builder $supplier) use ($term): void {
                        $supplier->where('name', 'like', $term);
                    });
            });
        }

        if (($filters['supplier_id'] ?? null) !== null) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        foreach (['status', 'payment_status'] as $column) {
            if (($filters[$column] ?? null) !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if (($filters['date_from'] ?? null) !== null) {
            $query->where('purchase_date', '>=', $filters['date_from']);
        }

        if (($filters['date_to'] ?? null) !== null) {
            $query->where('purchase_date', '<=', $filters['date_to']);
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one purchase inside the tenant, with everything the API detail
     * view and the update guard need, or fail with a 404 envelope.
     *
     * Eager loading here (supplier, creator, items with their products and
     * ledger-derived stock) keeps the detail a constant number of queries —
     * never one query per row.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $purchaseId): Purchase
    {
        return Purchase::query()
            ->forBusiness($businessId)
            ->with([
                'supplier',
                'creator:id,name',
                'purchaseItems.product' => static function ($product) use ($businessId): void {
                    $product->withCurrentStock($businessId);
                },
            ])
            ->whereKey($purchaseId)
            ->firstOrFail();
    }

    /**
     * Create a purchase with its items and stock-in movements atomically.
     *
     * Everything runs inside one `DB::transaction`:
     *  1. resolve + re-verify every product server-side (ownership and stock
     *     tracking) and compute all money with fixed-point arithmetic;
     *  2. write the purchase header with **server-calculated** totals;
     *  3. write one `purchase_items` row per line;
     *  4. append one signed stock movement per line through
     *     {@see InventoryService::record()}, referencing the purchase.
     *
     * If any step throws (business rule or database error) the whole
     * transaction rolls back — no purchase, no items, no ledger rows — so a
     * failed request can never leave partial state behind.
     *
     * @param  array<string, mixed>  $data  Validated payload (no totals, no business_id).
     *
     * @throws BusinessRuleException when a rule derived from stored state is violated.
     */
    public function create(int $businessId, ?int $userId, array $data): Purchase
    {
        return DB::transaction(function () use ($businessId, $userId, $data): Purchase {
            // Resolve and verify every product before writing anything.
            $lines = $this->resolvedLines($businessId, $data['items']);

            // Header discount may not exceed what the lines add up to, so the
            // grand total can never come out negative.
            $subtotal = '0.00';
            foreach ($lines as $line) {
                $subtotal = Money::add($subtotal, $line['total']);
            }

            $discount = Money::format($data['discount'] ?? 0);
            if (Money::greaterThan($discount, $subtotal)) {
                throw new BusinessRuleException('Purchase discount cannot exceed the subtotal.');
            }

            $tax = Money::format($data['tax'] ?? 0);
            $grandTotal = Money::subtract(Money::add($subtotal, $tax), $discount);

            $paidAmount = Money::format($data['paid_amount'] ?? 0);
            if (Money::greaterThan($paidAmount, $grandTotal)) {
                throw new BusinessRuleException('Paid amount cannot exceed the purchase total.');
            }
            $dueAmount = Money::subtract($grandTotal, $paidAmount);

            $purchase = Purchase::create([
                'business_id' => $businessId,
                'supplier_id' => (int) $data['supplier_id'],
                'invoice_number' => $data['invoice_number'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $this->paymentStatus($grandTotal, $paidAmount),
                'status' => Purchase::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $purchase->purchaseItems()->create([
                    'product_id' => $line['product']->getKey(),
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'discount' => $line['discount'],
                    'tax' => $line['tax'],
                    'total' => $line['total'],
                ]);

                // Stock in: a positive signed quantity appended to the ledger,
                // traceable back to this purchase through the reference morph.
                $this->inventory->record(
                    $line['product'],
                    $userId,
                    StockMovement::TYPE_PURCHASE,
                    $line['quantity'],
                    'Purchase #'.$purchase->getKey(),
                    Purchase::class,
                    $purchase->getKey(),
                );
            }

            return $this->find($businessId, $purchase->getKey());
        });
    }

    /**
     * Update the safe, non-structural fields of a purchase (PUT and PATCH).
     *
     * A purchase that has posted stock movements is finalized: its items and
     * totals define what entered the ledger, so they are immutable (editing
     * them without rewriting history would desynchronize inventory). The
     * service therefore rejects `items`, `subtotal`, `discount`, `tax` and
     * `grand_total` with a controlled 409 — unless the client re-sent the
     * exact stored document (an unchanged full PUT), which is a harmless
     * no-op.
     *
     * Editable: supplier (same business), invoice number, purchase date,
     * notes and the purchase-side paid amount, which recomputes
     * `due_amount` / `payment_status` server-side. No ledger row is written
     * or changed by an update.
     *
     * @param  array<string, mixed>  $data  Validated payload.
     *
     * @throws BusinessRuleException when a structural change is attempted.
     */
    public function update(Purchase $purchase, array $data): Purchase
    {
        $this->guardStructuralChanges($purchase, $data);

        $attributes = [];

        if (array_key_exists('supplier_id', $data)) {
            $supplierId = (int) $data['supplier_id'];
            if ($supplierId !== (int) $purchase->supplier_id) {
                // Defense in depth: the Form Request already proved the
                // supplier belongs to this business; re-verify server-side.
                $supplier = Supplier::query()
                    ->forBusiness($purchase->business_id)
                    ->whereKey($supplierId)
                    ->first();

                if ($supplier === null) {
                    throw new BusinessRuleException('Supplier does not belong to this business.');
                }

                $attributes['supplier_id'] = $supplierId;
            }
        }

        foreach (['invoice_number', 'purchase_date', 'notes'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $data[$column];
            }
        }

        if (array_key_exists('paid_amount', $data)) {
            $paidAmount = Money::format($data['paid_amount']);
            $grandTotal = Money::format($purchase->grand_total);

            if (Money::greaterThan($paidAmount, $grandTotal)) {
                throw new BusinessRuleException('Paid amount cannot exceed the purchase total.');
            }

            $attributes['paid_amount'] = $paidAmount;
            $attributes['due_amount'] = Money::subtract($grandTotal, $paidAmount);
            $attributes['payment_status'] = $this->paymentStatus($grandTotal, $paidAmount);
        }

        if ($attributes !== []) {
            $purchase->fill($attributes)->save();
        }

        return $this->find($purchase->business_id, $purchase->id);
    }

    /**
     * Reject any payload that would rewrite the finalized money or item
     * structure of a purchase. A byte-equivalent re-send (an unchanged full
     * PUT) passes through as a no-op.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException when the payload differs from the stored document.
     */
    private function guardStructuralChanges(Purchase $purchase, array $data): void
    {
        foreach (['subtotal', 'discount', 'tax', 'grand_total'] as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            if (! Money::equals($data[$column], $purchase->{$column})) {
                throw new BusinessRuleException(self::FINALIZED_MESSAGE);
            }
        }

        if (array_key_exists('items', $data) && ! $this->matchesStoredItems($purchase, $data['items'])) {
            throw new BusinessRuleException(self::FINALIZED_MESSAGE);
        }
    }

    /**
     * Whether the incoming item collection is identical to the stored one
     * (same lines and same server-computed line totals, order-insensitive).
     *
     * @param  mixed  $incoming
     */
    private function matchesStoredItems(Purchase $purchase, mixed $incoming): bool
    {
        if (! is_array($incoming)) {
            return false;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, PurchaseItem> $stored */
        $stored = $purchase->purchaseItems;

        if ($stored->count() !== count($incoming)) {
            return false;
        }

        $storedSignatures = $stored
            ->map(fn (PurchaseItem $item): string => $this->itemSignature(
                (int) $item->product_id,
                Quantity::format($item->quantity),
                $item->unit_cost,
                $item->discount,
                $item->tax,
                $item->total,
            ))
            ->sort()
            ->values()
            ->all();

        $incomingSignatures = [];

        foreach ($incoming as $line) {
            if (! isset($line['product_id'], $line['quantity'], $line['unit_cost'])) {
                return false;
            }

            $quantity = Quantity::format($line['quantity']);
            $unitCost = Money::format($line['unit_cost']);
            $discount = Money::format($line['discount'] ?? 0);
            $tax = Money::format($line['tax'] ?? 0);
            $total = Money::add(Money::subtract(Money::multiply($quantity, $unitCost), $discount), $tax);

            $incomingSignatures[] = $this->itemSignature(
                (int) $line['product_id'],
                $quantity,
                $unitCost,
                $discount,
                $tax,
                $total,
            );
        }

        sort($incomingSignatures);

        return $storedSignatures === $incomingSignatures;
    }

    /**
     * Canonical, scale-normalised representation of one purchase line.
     */
    private function itemSignature(
        int $productId,
        string $quantity,
        string $unitCost,
        string $discount,
        string $tax,
        string $total,
    ): string {
        return implode('|', [
            $productId,
            Quantity::format($quantity),
            Money::format($unitCost),
            Money::format($discount),
            Money::format($tax),
            Money::format($total),
        ]);
    }

    /**
     * Delete a purchase that never affected inventory.
     *
     * Phase 21 does not implement ledger reversal: `purchases` have no soft
     * delete, `payments.purchase_id` would be nulled by the FK and stock
     * already consumed downstream could not be given back safely. A purchase
     * that posted movements (or has recorded payments) therefore produces a
     * controlled 409 and everything stays untouched — the ledger is never
     * left inconsistent and no orphaned movement is ever created. Only a
     * purchase with no ledger effect and no payments is removed, cascading
     * its `purchase_items` with it.
     *
     * @throws BusinessRuleException when deletion would corrupt history or inventory.
     */
    public function delete(Purchase $purchase): void
    {
        $affectsInventory = StockMovement::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->getKey())
            ->exists();

        if ($affectsInventory) {
            throw new BusinessRuleException('Cannot delete a purchase that has already affected inventory.');
        }

        if ($purchase->payments()->exists()) {
            throw new BusinessRuleException('Cannot delete a purchase with recorded payments.');
        }

        $purchase->delete();
    }

    /**
     * Derive `payment_status` from the server-side totals.
     */
    private function paymentStatus(string $grandTotal, string $paidAmount): string
    {
        if (Money::equals($paidAmount, $grandTotal)) {
            return Purchase::PAYMENT_STATUS_PAID;
        }

        if (Money::isZero($paidAmount)) {
            return Purchase::PAYMENT_STATUS_PENDING;
        }

        return Purchase::PAYMENT_STATUS_PARTIAL;
    }

    /**
     * Re-verify every line server-side and compute its money.
     *
     * The Form Request already validated structure and ownership; this method
     * repeats the security-critical checks (a product of another business can
     * never be purchased) and rejects products that do not track stock, so
     * the ledger can never receive a meaningless balance. All arithmetic is
     * fixed-point through {@see Money}/{@see Quantity} — never a float.
     *
     * @param  array<int, mixed>  $items  Validated `items` collection.
     * @return array<int, array{product: Product, quantity: string, unit_cost: string, discount: string, tax: string, total: string}>
     *
     * @throws BusinessRuleException when a product is invalid or does not track stock.
     */
    private function resolvedLines(int $businessId, array $items): array
    {
        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $items,
        )));

        $products = Product::query()
            ->forBusiness($businessId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']);

            if ($product === null) {
                throw new BusinessRuleException('One or more products are invalid.');
            }

            if (! $product->tracksStock()) {
                throw new BusinessRuleException(sprintf('%s does not track stock.', $product->name));
            }

            $quantity = Quantity::format($item['quantity']);
            $unitCost = Money::format($item['unit_cost']);
            $lineGross = Money::multiply($quantity, $unitCost);

            $discount = Money::format($item['discount'] ?? 0);
            if (Money::greaterThan($discount, $lineGross)) {
                throw new BusinessRuleException(sprintf(
                    'Item discount for %s exceeds its line total.',
                    $product->name,
                ));
            }

            $tax = Money::format($item['tax'] ?? 0);

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'discount' => $discount,
                'tax' => $tax,
                'total' => Money::add(Money::subtract($lineGross, $discount), $tax),
            ];
        }

        return $lines;
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Purchase>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Purchase>
     */
    private function applyOrdering(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'asc'
            ? 'asc'
            : 'desc';

        return $query->orderBy($column, $direction)->orderBy('id');
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



