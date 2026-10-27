<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @use HasFactory<ProductFactory>
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Statuses accepted by the API/validation layer. */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * Unit value that marks a product as a service.
     *
     * Services are not stock tracked: they never hold a ledger balance and the
     * manual stock adjustment endpoint rejects them.
     */
    public const UNIT_SERVICE = 'service';

    protected $fillable = [
        'business_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'unit',
        'low_stock_threshold',
        'image',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'low_stock_threshold' => 'integer',
            'status' => 'string',
            'business_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Restrict the query to one tenant.
     *
     * Every product the API touches is resolved through this scope so a
     * business can never read or mutate another business's product.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * Select the on-hand quantity derived from the stock movement ledger.
     *
     * `stock_movements` is the single source of truth for inventory, and the
     * sum is produced by a correlated sub-query, so a product list stays one
     * SQL round trip with no N+1 stock lookups. The result is exposed as the
     * `current_stock` attribute (a decimal string, `null` when the ledger is
     * empty).
     *
     * `business_id` is constrained explicitly so the aggregate can never pick
     * up a movement that belongs to another tenant.
     */
    public function scopeWithCurrentStock(Builder $query, int $businessId): Builder
    {
        return $query->withSum(
            ['stockMovements as current_stock' => static function (Builder $movements) use ($businessId): void {
                $movements->where('business_id', $businessId);
            }],
            'quantity',
        );
    }

    /**
     * Whether the product holds a stock balance at all.
     *
     * Services (e.g. a haircut) are sold without inventory, so the ledger must
     * not be adjusted for them.
     */
    public function tracksStock(): bool
    {
        return $this->unit !== self::UNIT_SERVICE;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleReturnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
