<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class CashRegister extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** Statuses accepted by the API/validation layer. */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED];

    protected $fillable = [
        'business_id',
        'opened_by',
        'opened_at',
        'opening_balance',
        'closed_by',
        'closed_at',
        'closing_balance',
        'expected_balance',
        'difference',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'opening_balance' => 'decimal:2',
            'closed_at' => 'datetime',
            'closing_balance' => 'decimal:2',
            'expected_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'status' => 'string',
            'business_id' => 'integer',
            'opened_by' => 'integer',
            'closed_by' => 'integer',
        ];
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Restrict the query to one tenant (a foreign register is "not found").
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function latestTransaction(): HasOne
    {
        return $this->hasOne(CashRegisterTransaction::class)->latestOfMany();
    }
}
