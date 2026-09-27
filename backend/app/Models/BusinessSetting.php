<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One key/value configuration entry owned by a single business.
 *
 * `business_settings` stores free-form settings as `(business_id, key, value)`
 * rows with a unique `(business_id, key)` index, so a business can never read
 * or overwrite another business's configuration. The `value` column is a plain
 * `text` field; the service layer owns which keys are readable/writable and
 * keeps credential-shaped keys out of API responses.
 */
class BusinessSetting extends Model
{
    /** Longest `key` the column can hold. */
    public const MAX_KEY_LENGTH = 100;

    /** Longest `value` accepted through the API (bounded well under the column). */
    public const MAX_VALUE_LENGTH = 2000;

    protected $fillable = [
        'business_id',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'business_id' => 'integer',
        ];
    }

    /**
     * Restrict the query to one tenant.
     *
     * @param  Builder<BusinessSetting>  $query
     * @return Builder<BusinessSetting>
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * Restrict the query to an explicit set of keys.
     *
     * @param  Builder<BusinessSetting>  $query
     * @param  array<int, string>  $keys
     * @return Builder<BusinessSetting>
     */
    public function scopeWhereKeyIn(Builder $query, array $keys): Builder
    {
        return $query->whereIn($this->qualifyColumn('key'), $keys);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
