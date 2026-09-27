<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * @use HasFactory<UserFactory>
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'phone',
        'branch',
        'status',
        'notes',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'string',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withTimestamps();
    }

    /**
     * Every permission the user holds, through the user's own roles.
     *
     * `role_user` / `permission_role` are joined in a single query and the
     * result is memoised for the lifetime of this model instance, so repeated
     * `hasPermission()` calls during one request (Gate, middleware, controller)
     * cost at most one extra query instead of one per check.
     *
     * The join is additionally constrained to roles the user actually owns
     * (`roles.business_id = users.business_id`). Without that check a pivot row
     * pointing at another tenant's role would grant its permissions here — a
     * cross-tenant privilege-escalation hole. A user with no business holds
     * nothing.
     *
     * @return Collection<int, Permission>
     */
    public function permissions()
    {
        if ($this->relationLoaded('permissions')) {
            return $this->getRelation('permissions');
        }

        $permissions = Permission::query()
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->join('users', 'role_user.user_id', '=', 'users.id')
            ->where('users.id', $this->getKey())
            // The role must belong to the same business as the user holding it.
            ->whereColumn('roles.business_id', 'users.business_id')
            ->distinct()
            ->orderBy('permissions.id')
            ->get([
                'permissions.id',
                'permissions.name',
                'permissions.slug',
                'permissions.description',
                'permissions.created_at',
                'permissions.updated_at',
            ]);

        $this->setRelation('permissions', $permissions);

        return $permissions;
    }

    /**
     * Permission slugs granted to this user.
     *
     * Derived from the already-eager/memoised `permissions()` relation, so a
     * repeated check inside one request performs no additional query. The memo
     * lives on the model instance (never a static), so it cannot leak between
     * requests or between users in a long-lived worker.
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        return $this->permissions()->pluck('slug')->all();
    }

    /**
     * Does the user hold the given permission?
     *
     * Delegates to the Gate so route middleware, controllers and future phases
     * all share one implementation (see `PermissionService::grants()` and the
     * Gate definitions in `AppServiceProvider`).
     */
    public function hasPermission(string $permission): bool
    {
        return app(Gate::class)->forUser($this)->check($permission);
    }

    /**
     * Does the user hold at least one of the given permissions?
     *
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        $gate = app(Gate::class)->forUser($this);

        foreach ($permissions as $permission) {
            if ($gate->check($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the user hold the given role (matched by slug) inside their business?
     *
     * Useful for coarse checks such as "business owner"; roles are business-owned,
     * so the comparison is always made inside the user's own tenant.
     */
    public function hasRole(string $slug): bool
    {
        return $this->roles
            ->contains(static fn (Role $role): bool => $role->slug === $slug
                && (int) $role->business_id === (int) $this->business_id);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'created_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'cashier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function cashRegisterTransactions(): HasMany
    {
        return $this->hasMany(CashRegisterTransaction::class, 'created_by');
    }

    public function openedCashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'opened_by');
    }

    public function closedCashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'closed_by');
    }
}
