<?php

namespace App\Models;

use Database\Factories\InventoryBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @use HasFactory<InventoryBatchFactory>
 */
class InventoryBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'product_id',
        'purchase_item_id',
        'batch_number',
        'received_date',
        'expiry_date',
        'received_quantity',
        'remaining_quantity',
        'cost_price',
        'selling_price',
        'mrp',
        'selling_code',
    ];

    protected function casts(): array
    {
        return [
            'business_id' => 'integer',
            'product_id' => 'integer',
            'purchase_item_id' => 'integer',
            'received_date' => 'date',
            'expiry_date' => 'date',
            'received_quantity' => 'decimal:4',
            'remaining_quantity' => 'decimal:4',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'selling_code' => 'string',
        ];
    }

    /**
     * Restrict the query to one tenant.
     *
     * Every batch the API touches is resolved through this scope so a
     * business can never read another business's batches.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }
}
