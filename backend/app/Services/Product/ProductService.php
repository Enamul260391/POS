<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for the product catalogue.
 *
 * Products hold master data only — there is no stock column to write. On-hand
 * quantity is always derived from the `stock_movements` ledger
 * ({@see Product::scopeWithCurrentStock()}), so this service owns no inventory
 * arithmetic; the single exception is `opening_stock` at creation time, which
 * is handed to {@see InventoryService} as a ledger row inside a transaction.
 */
final class ProductService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = [
        'name', 'sku', 'selling_price', 'cost_price',
        'low_stock_threshold', 'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'created_at';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** Unit applied when the client does not choose one (column default). */
    public const DEFAULT_UNIT = 'pcs';

    /** Ledger note written for the movement created by `opening_stock`. */
    private const OPENING_STOCK_NOTE = 'Opening stock';

    /** Master-data columns a client may change. */
    private const EDITABLE_COLUMNS = [
        'category_id', 'name', 'sku', 'barcode', 'description',
        'cost_price', 'selling_price', 'tax_rate', 'unit',
        'low_stock_threshold', 'image', 'status',
    ];

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Paginated, tenant-scoped product list.
     *
     * Search, category, status, SKU and barcode filters all run in SQL, the
     * category is eager loaded (no N+1) and the current stock arrives as a
     * correlated sub-query over the ledger, so the list is a constant number of
     * queries regardless of page size.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($businessId)->with('category');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term);
            });
        }

        if (($filters['category_id'] ?? null) !== null) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        // Exact matches: a scanned barcode or a typed SKU is not a fuzzy query.
        foreach (['sku', 'barcode'] as $column) {
            $value = $filters[$column] ?? null;
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one product inside the tenant (with category and current stock),
     * or fail with a 404 envelope.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $productId): Product
    {
        return $this->query($businessId)
            ->with('category')
            ->whereKey($productId)
            ->firstOrFail();
    }

    /**
     * Create a product for the given business.
     *
     * The tenant always comes from the caller. When `opening_stock` is greater
     * than zero the initial quantity is written to the ledger as a `receive`
     * movement — never stored on the product — and because that is two writes
     * the whole operation runs in one transaction: a failure leaves neither a
     * product nor a movement behind.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, ?int $userId, array $data): Product
    {
        return DB::transaction(function () use ($businessId, $userId, $data): Product {
            $product = Product::create([
                ...$this->masterData($data),
                'business_id' => $businessId,
            ]);

            $openingStock = $data['opening_stock'] ?? 0;

            if (Quantity::isPositive($openingStock)) {
                $this->inventory->record(
                    product: $product,
                    userId: $userId,
                    type: StockMovement::TYPE_RECEIVE,
                    quantity: Quantity::format($openingStock),
                    note: self::OPENING_STOCK_NOTE,
                );
            }

            return $this->find($businessId, $product->id);
        });
    }

    /**
     * Update the editable master data of a product.
     *
     * Only the fields present in `$data` are written, so both PUT and PATCH
     * behave as a partial update. `business_id` and `id` can never be present
     * (they are not part of the validated payload) and no stock column exists
     * to change — inventory only moves through the ledger.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $attributes = Arr::only($data, self::EDITABLE_COLUMNS);

        if ($attributes !== []) {
            $product->fill($attributes)->save();
        }

        return $this->find($product->business_id, $product->id);
    }

    /**
     * Soft-delete a product.
     *
     * `products` uses soft deletion since Phase 17, which is exactly what the
     * history needs: the row disappears from every list while `stock_movements`,
     * `sale_items`, `purchase_items` and `sale_return_items` keep pointing at
     * it, so the inventory ledger and every past transaction stay intact and the
     * product can be restored. A hard delete would null those foreign keys
     * (`nullOnDelete`), which is why this service never forces a delete.
     */
    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Base query: one tenant, with the ledger-derived stock pre-selected.
     *
     * @return Builder<Product>
     */
    private function query(int $businessId): Builder
    {
        return Product::query()
            ->forBusiness($businessId)
            ->withCurrentStock($businessId);
    }

    /**
     * Master data with the table defaults applied for an omitted field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function masterData(array $data): array
    {
        return [
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'description' => $data['description'] ?? null,
            'cost_price' => $data['cost_price'] ?? 0,
            'selling_price' => $data['selling_price'] ?? 0,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'unit' => $data['unit'] ?? self::DEFAULT_UNIT,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? 0,
            'image' => $data['image'] ?? null,
            'status' => $data['status'] ?? Product::STATUS_ACTIVE,
        ];
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
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
