<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A system-defined, business-agnostic capability such as `roles.update`.
 *
 * The `permissions` table carries no `business_id`: the catalogue is global
 * (see `create_permissions_table`), and each business grants a subset of it to
 * its own roles through `permission_role`. Consequently permission CRUD is not
 * exposed by the API — permissions are created by the seeder and only read
 * here.
 */
class Permission extends Model
{
    /** Longest `name` the column can hold. */
    public const MAX_NAME_LENGTH = 100;

    /** Longest `slug` the column can hold; also the value used for checks. */
    public const MAX_SLUG_LENGTH = 100;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * Free-text search over the human-readable columns.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
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

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')
            ->withTimestamps();
    }
}
