<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use HasFactory;
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_COMPLETED];

    protected $fillable = [
        'business_id',
        'sale_id',
        'customer_id',
        'return_number',
        'return_date',
        'reason',
        'subtotal',
        'tax',
        'refund_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'status' => 'string',
            'business_id' => 'integer',
            'sale_id' => 'integer',
            'customer_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Restrict the query to one tenant (a foreign return is "not found").
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function saleReturnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    /**
     * The refund payments this return settled, one row per method.
     *
     * Every refund against the return points back here through
     * `payments.sale_return_id`, so the settlement of a return is readable from
     * the return itself without searching the payments table for it.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Only the refunds — the outbound payments this return caused.
     *
     * Scoped rather than relying on every row in the relation being a refund:
     * the guarantee a reader needs is "this return has not been settled twice",
     * and that has to hold whatever else ever gets linked to a return.
     */
    public function refundPayments(): HasMany
    {
        return $this->hasMany(Payment::class)->where('type', Payment::TYPE_REFUND)->orderBy('id');
    }
}
