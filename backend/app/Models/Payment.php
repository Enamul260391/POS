<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_CARD = 'card';

    public const PAYMENT_METHOD_BANK = 'bank';

    public const PAYMENT_METHOD_CHEQUE = 'cheque';

    public const PAYMENT_METHOD_DUE = 'due';

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_CARD,
        self::PAYMENT_METHOD_BANK,
        self::PAYMENT_METHOD_CHEQUE,
        self::PAYMENT_METHOD_DUE,
    ];

    protected $fillable = [
        'business_id',
        'sale_id',
        'purchase_id',
        'payment_method',
        'amount',
        'reference',
        'paid_at',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payment_method' => 'string',
            'business_id' => 'integer',
            'sale_id' => 'integer',
            'purchase_id' => 'integer',
            'received_by' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Restrict the query to one tenant (a foreign payment is "not found").
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
