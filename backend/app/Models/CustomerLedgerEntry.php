<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable movement in a customer's account.
 *
 * A customer's position is the *sum* of these rows rather than a column that
 * gets edited, which is what makes the history complete: `opening_balance`
 * records where the relationship started, and everything after it is a dated
 * entry that says which document caused it.
 *
 * ## Sign convention
 *
 * **Positive = the customer owes the business. Negative = the business owes the
 * customer.** The same rule for every type, with no exceptions to remember:
 *
 *     OPENING_BALANCE   +5,000   they started owing
 *     SALE_DUE           +2,000   bought on credit
 *     CUSTOMER_PAYMENT   -1,000   paid us back
 *     SALE_RETURN          -500   goods came back, so less is owed
 *     --------------------------
 *     Outstanding        +5,500
 *
 * A balance that goes negative therefore reads correctly on its own: it is an
 * advance the business holds, not a fabricated negative receivable.
 *
 * Nothing here is ever updated or deleted. A correcting document is a new,
 * opposing entry, so the sequence always explains itself.
 */
class CustomerLedgerEntry extends Model
{
    use HasFactory;

    /**
     * Where the account stood when the customer was added — written once, from
     * the `opening_balance` the customer was created with, and never revisited.
     * The `customers` column is left as the historical original so the opening
     * figure is still readable exactly as it was entered.
     */
    public const TYPE_OPENING_BALANCE = 'opening_balance';

    /** A credit sale added to what the customer owes. */
    public const TYPE_SALE_DUE = 'sale_due';

    /** The customer paid money against what they owed. */
    public const TYPE_CUSTOMER_PAYMENT = 'customer_payment';

    /** A return reduced what the customer owes, or created a credit. */
    public const TYPE_SALE_RETURN = 'sale_return';

    /**
     * A deliberate correction entered by hand.
     *
     * Present because an accountant fixing a mistake must be able to record
     * that they did, rather than editing history to hide it.
     */
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_OPENING_BALANCE,
        self::TYPE_SALE_DUE,
        self::TYPE_CUSTOMER_PAYMENT,
        self::TYPE_SALE_RETURN,
        self::TYPE_ADJUSTMENT,
    ];

    /**
     * Types that can *increase* the balance, i.e. the customer ends up owing
     * more.
     *
     * Used to validate a manual adjustment before it is written, so a
     * correction cannot silently be filed as the opposite movement.
     */
    public const INCREASING_TYPES = [
        self::TYPE_OPENING_BALANCE,
        self::TYPE_SALE_DUE,
        self::TYPE_ADJUSTMENT,
    ];

    protected $table = 'customer_ledger_entries';

    protected $fillable = [
        'business_id',
        'customer_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'type' => 'string',
            'business_id' => 'integer',
            'customer_id' => 'integer',
            'reference_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Restrict the query to one tenant.
     *
     * The ledger is as sensitive as the customer's own details, so it is read
     * and written through this scope exactly like every other tenant-owned
     * model: one business can never observe another's balances.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * Restrict the query to one customer.
     */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where($this->qualifyColumn('customer_id'), $customerId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The document that caused this movement — a `Sale`, `SaleReturn` or
     * `Payment`, depending on {@see self::$type}.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
