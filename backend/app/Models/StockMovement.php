<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the inventory ledger.
 *
 * `stock_movements` is the single source of truth for on-hand quantity: the
 * current stock of a product is the sum of its movements, never a mutable
 * column on `products`.
 *
 * Ledger semantics of the existing (Phase 16) schema:
 *
 *  - `quantity` (`decimal(15, 4)`) is the **signed change** applied to stock:
 *    positive adds units, negative removes them. `SUM(quantity)` per product is
 *    therefore the current stock.
 *  - `type` labels *why* the change happened. Manual adjustments use
 *    `receive` / `remove` / `set` (the vocabulary the POS UI is built on);
 *    purchases write their own `purchase` label and later phases (sales,
 *    returns) add theirs — all of them keep writing signed quantities.
 *  - `reference_type` / `reference_id` point at the document that caused the
 *    movement (purchase, sale, return) and stay null for manual adjustments.
 *  - `created_by` records the user who wrote the row.
 */
class StockMovement extends Model
{
    /** Manual adjustment: units added to stock. */
    public const TYPE_RECEIVE = 'receive';

    /** Manual adjustment: units taken out of stock. */
    public const TYPE_REMOVE = 'remove';

    /** Manual adjustment: physical recount that overrides the calculated stock. */
    public const TYPE_SET = 'set';

    /** Stock received from a supplier purchase (written by the purchase flow). */
    public const TYPE_PURCHASE = 'purchase';

    /** Stock removed by a customer sale (written by the sale flow). */
    public const TYPE_SALE = 'sale';

    /** Stock restored by a sale return (written by the return flow). */
    public const TYPE_SALE_RETURN = 'sale_return';

    /** Movement types the manual stock adjustment endpoint accepts. */
    public const ADJUSTMENT_TYPES = [
        self::TYPE_RECEIVE,
        self::TYPE_REMOVE,
        self::TYPE_SET,
    ];

    /**
     * Movement types the movement-log filter accepts: the manual adjustment
     * vocabulary plus the `purchase` rows the purchase flow writes, so the
     * whole ledger can be filtered without exposing an unbounded type list.
     */
    public const LOG_TYPES = [
        self::TYPE_RECEIVE,
        self::TYPE_REMOVE,
        self::TYPE_SET,
        self::TYPE_PURCHASE,
        self::TYPE_SALE,
        self::TYPE_SALE_RETURN,
    ];

    protected $fillable = [
        'business_id',
        'product_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reference_id' => 'integer',
            'created_by' => 'integer',
            'type' => 'string',
            'business_id' => 'integer',
            'product_id' => 'integer',
            'batch_id' => 'integer',
        ];
    }

    /**
     * Restrict the query to one tenant.
     *
     * The ledger is always read through this scope (per-product aggregates
     * included) so a movement can never leak across businesses.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * Restrict the query to one product.
     */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where($this->qualifyColumn('product_id'), $productId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
