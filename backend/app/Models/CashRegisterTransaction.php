<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashRegisterTransaction extends Model
{
    use HasFactory;

    /** Marker written once when a register is opened (informational, excluded from flow sums). */
    public const TYPE_OPENING = 'opening';

    /** Cash received from a customer sale (reserved for automatic posting). */
    public const TYPE_SALE = 'sale';

    /** Cash paid out for an expense (written automatically by the expense flow). */
    public const TYPE_EXPENSE = 'expense';

    /**
     * Cash handed back to a customer for a sale return (written automatically
     * by the return flow).
     *
     * This is the cash-drawer projection of a `SaleReturn`, exactly as `sale`
     * is the projection of a `Payment` and `expense` of an `Expense`. It is an
     * outflow, so it belongs to {@see self::OUTFLOW_TYPES} and therefore lowers
     * the balance the ledger aggregate derives.
     *
     * It is deliberately **not** a manual type: every row carries a
     * `reference_type` / `reference_id` pointing at the return that caused it,
     * which is what keeps the manual endpoints from amending or deleting it
     * (see `CashRegisterService::isManual()`).
     */
    public const TYPE_REFUND = 'refund';

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
        self::TYPE_REFUND,
        self::TYPE_CASH_IN,
        self::TYPE_CASH_OUT,
        self::TYPE_CLOSING,
    ];

    /** Types the manual transaction endpoint accepts. */
    public const MANUAL_TYPES = [self::TYPE_CASH_IN, self::TYPE_CASH_OUT];

    /**
     * Types a document flow may post on its own.
     *
     * The whitelist {@see CashRegisterService::postAutomaticTransaction()}
     * enforces, so a caller cannot invent an arbitrary automatic movement: only
     * a sale, an expense or a return refund may be posted this way.
     */
    public const AUTOMATIC_TYPES = [self::TYPE_SALE, self::TYPE_EXPENSE, self::TYPE_REFUND];

    /** Types that increase the drawer balance. */
    public const INFLOW_TYPES = [self::TYPE_SALE, self::TYPE_CASH_IN];

    /** Types that decrease the drawer balance. */
    public const OUTFLOW_TYPES = [self::TYPE_EXPENSE, self::TYPE_REFUND, self::TYPE_CASH_OUT];

    /**
     * Types that record a **balance** rather than a movement.
     *
     * The `opening` float and the `closing` count are written by
     * `CashRegisterService::open()` / `close()` to mark the shift, and they are
     * deliberately in neither {@see self::INFLOW_TYPES} nor
     * {@see self::OUTFLOW_TYPES}. The drawer balance is
     * `opening_balance + inflow − outflow`, so an opening float counted as an
     * inflow would be added twice and a closing count counted as an outflow would
     * subtract money that was never removed — the drawer was reconciled, not
     * emptied.
     *
     * Reported separately by Reports Stage 9 rather than folded into a cash
     * direction, so a client summing the breakdown can never add a shift's float
     * to the period's cash-in. Kept in step with {@see self::INFLOW_TYPES} /
     * {@see self::OUTFLOW_TYPES}: a movement type is a member of exactly one of
     * the three lists, and the test suite asserts that partition is complete.
     */
    public const NON_MOVEMENT_TYPES = [self::TYPE_OPENING, self::TYPE_CLOSING];

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
