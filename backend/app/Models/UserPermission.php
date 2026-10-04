<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-specific permission override.
 *
 * A row records that one user's access to one permission is forced to `allow`
 * or `deny`, regardless of what the user's roles grant. Authorization reads
 * these rows through `User::effectivePermissionSlugs()` /
 * `User::deniedPermissionSlugs()` and applies the precedence
 * `user DENY > user ALLOW > role permission > no access`.
 *
 * Overrides are tenant-owned even though the `permissions` catalogue is global:
 * every row carries the `business_id` of the user it belongs to, and the
 * application layer keeps that equal to `users.business_id` when writing rows
 * (the `user_permissions` migration documents why the DB cannot).
 */
class UserPermission extends Model
{
    /** Force the permission on for this user. */
    public const EFFECT_ALLOW = 'allow';

    /** Force the permission off for this user. */
    public const EFFECT_DENY = 'deny';

    /** The only two effects an override may hold. */
    public const EFFECTS = [
        self::EFFECT_ALLOW,
        self::EFFECT_DENY,
    ];

    protected $fillable = [
        'business_id',
        'user_id',
        'permission_id',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'business_id' => 'integer',
            'user_id' => 'integer',
            'permission_id' => 'integer',
            'effect' => 'string',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
