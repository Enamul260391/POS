<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @use HasFactory<CustomerFactory>
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Statuses accepted by the API/validation layer. */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'address',
        'opening_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'status' => 'string',
            'business_id' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Restrict the query to one tenant.
     *
     * Every customer the API touches is resolved through this scope so a
     * business can never read or mutate another business's customer.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /**
     * Every movement recorded against this customer's account, oldest first.
     *
     * The current balance is the sum of these rows — `opening_balance` says
     * where the account started and the ledger says everything since — which
     * is why `opening_balance` is left untouched as historical data rather
     * than being kept up to date as if it were a running total.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class)->orderBy('id');
    }
}
