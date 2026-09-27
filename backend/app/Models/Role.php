<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** Longest `name` the column can hold. */
    public const MAX_NAME_LENGTH = 50;

    /** Longest `slug` the column can hold. */
    public const MAX_SLUG_LENGTH = 50;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
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
     * Every role the API touches is resolved through this scope, so a role
     * belonging to another business is simply "not found" and its existence is
     * never leaked. `business_id` itself always comes from the authenticated
     * user, never from the request.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * Free-text search across the human-readable columns.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(static function (Builder $nested) use ($term): void {
            $nested->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhere('description', 'like', $term);
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withTimestamps();
    }
}
