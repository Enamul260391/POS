<?php

namespace App\Models;

use App\Services\Sale\InvoiceNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-business invoice counter that numbers sales.
 *
 * Exactly one row exists per business (enforced by a unique index on
 * `business_id`), so a number is only ever handed out under that row's lock.
 * The row is deliberately tiny — an integer cursor — because it is written on
 * every single checkout and must never become a contention point for anything
 * other than the number itself.
 *
 * See {@see InvoiceNumberService} for the allocation rules.
 */
class InvoiceSequence extends Model
{
    /** Number handed out to the first sale of a business. */
    public const FIRST_SEQUENCE = 1;

    protected $fillable = [
        'business_id',
        'next_number',
    ];

    protected function casts(): array
    {
        return [
            'business_id' => 'integer',
            'next_number' => 'integer',
        ];
    }

    /**
     * Restrict the query to one tenant.
     *
     * @param  Builder<InvoiceSequence>  $query
     * @return Builder<InvoiceSequence>
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
