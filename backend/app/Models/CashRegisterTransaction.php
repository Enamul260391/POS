<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class CashRegisterTransaction extends Model
{
    use HasFactory;

    /** Marker written once when a register is opened (informational, excluded from flow sums). */
    public const TYPE_OPENING = 'opening';

    /** Cash received from a customer sale (reserved for automatic posting). */
    public const TYPE_SALE = 'sale';

    /** Cash paid out for an expense (written automatically by the expense flow). */
    public const TYPE_EXPENSE = 'expense';

    /** Manual cash added to the drawer. */
    public const TYPE_CASH_IN = 'cash_in';

    /** Manual cash removed from the drawer. */
    public const TYPE_CASH_OUT = 'cash_out';

    /** Marker written once when a register is closed (informational, excluded from flow sums). */
    public const TYPE_CLOSING = 'closing';

    /** Every type the `type` column may hold. */
    public const TYPES = [
        self::TYPE_OPENING,
        self::TYPE_SALE,
        self::TYPE_EXPENSE,
        self::TYPE_CASH_IN,
        self::TYPE_CASH_OUT,
        self::TYPE_CLOSING,
    ];

    /** Types the manual transaction endpoint accepts. */
    public const MANUAL_TYPES = [self::TYPE_CASH_IN, self::TYPE_CASH_OUT];

    /** Types that increase the drawer balance. */
    public const INFLOW_TYPES = [self::TYPE_SALE, self::TYPE_CASH_IN];

    /** Types that decrease the drawer balance. */
    public const OUTFLOW_TYPES = [self::TYPE_EXPENSE, self::TYPE_CASH_OUT];

    protected $fillable = [
        'business_id',
        'cash_register_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reference_id' => 'integer',
            'type' => 'string',
            'business_id' => 'integer',
            'cash_register_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Restrict the query to one tenant.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
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
