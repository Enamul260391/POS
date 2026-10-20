<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\InventoryBatch;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount',
        'tax',
        'total',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'sale_id' => 'integer',
            'product_id' => 'integer',
            'batch_id' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleReturnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
