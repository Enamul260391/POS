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

    /**
     * Money the business **received** — the ordinary meaning of this table.
     *
     * `payment_method` already describes *how*, never *which way*: a `card`
     * payment and a `card` refund are the same method with opposite direction.
     * This column is what tells them apart, so no refund can be mistaken for
     * an ordinary collection when a report sums a sale's payments.
     */
    public const TYPE_PAYMENT = 'payment';

    /** Money the business **paid back**, always against a `SaleReturn`. */
    public const TYPE_REFUND = 'refund';

    public const TYPES = [self::TYPE_PAYMENT, self::TYPE_REFUND];

    /**
     * Methods a refund may be paid out through.
     *
     * `due` is deliberately absent: nothing was ever collected through it, so
     * there is no money to hand back. A return that reaches the `due` portion
     * is settled on the customer's account instead — see
     * `CustomerLedgerEntry`.
     */
    public const REFUND_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_CARD,
        self::PAYMENT_METHOD_BANK,
        self::PAYMENT_METHOD_CHEQUE,
    ];

    protected $fillable = [
        'business_id',
        'sale_id',
        'purchase_id',
        'sale_return_id',
        'type',
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
            'type' => 'string',
            'payment_method' => 'string',
            'business_id' => 'integer',
            'sale_id' => 'integer',
            'purchase_id' => 'integer',
            'sale_return_id' => 'integer',
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

    /**
     * Only the money the business received.
     *
     * Every aggregate of "what this sale collected" must read through this
     * scope. Without it a refund — which is written against a return, not the
     * sale — would still be excluded by the `sale_id` link, but relying on that
     * alone would make correctness a property of how the row was filled in
     * rather than a guarantee the schema states.
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('type'), self::TYPE_PAYMENT);
    }

    /**
     * Only money paid back out.
     */
    public function scopeRefunds(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('type'), self::TYPE_REFUND);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * The return this refund settles, or `null` for an ordinary payment.
     *
     * Non-null exactly when {@see self::TYPE_REFUND} is set, so a refund is
     * always traceable to the document that caused it.
     */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Is this row money leaving the business?
     */
    public function isRefund(): bool
    {
        return $this->type === self::TYPE_REFUND;
    }
}
