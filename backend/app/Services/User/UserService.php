<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business logic for the staff directory (`/users`).
 *
 * Every read and write is scoped to the authenticated user's `business_id`,
 * which the controller always derives from the bearer token — a client-supplied
 * `business_id` is never read, so a user of another tenant is simply "not
 * found" and its existence is never leaked.
 *
 * Roles are **many-to-many** (`role_user`), but the management screen edits one
 * role at a time. Every method therefore accepts a single `role_id` and syncs
 * exactly that one role, so the list can never drift into a state the screen
 * cannot represent. The multi-role endpoint (`UserRoleController`) remains the
 * way to grant additional roles.
 */
final class UserService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = ['name', 'email', 'status', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Paginated, tenant-scoped staff list.
     *
     * `roles` is eager-loaded because the resource renders the role name, so
     * the list stays a fixed number of queries with no N+1.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($businessId);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('branch', 'like', $term);
            });
        }

        $status = $filters['status'] ?? null;
        if ($status !== null) {
            $query->where('status', $status);
        }

        $roleId = $filters['role_id'] ?? null;
        if ($roleId !== null) {
            $query->whereHas('roles', static fn (Builder $nested): Builder => $nested
                ->whereKey($roleId));
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one user inside the tenant, or fail with a 404 envelope.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $businessId, int $userId): User
    {
        return $this->query($businessId)->whereKey($userId)->firstOrFail();
    }
    /**
     * Create a staff account for the given business, with one role attached.
     *
     * The user row and the `role_user` pivot are written in one transaction:
     * an account with no role would be unable to do anything, so a partial
     * write would leave an unusable login behind. The password is hashed by the
     * model's `password => hashed` cast and is never echoed back.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the role does not belong to the business.
     */
    public function create(int $businessId, array $data): User
    {
        $roleId = $this->assertRoleBelongsToBusiness($businessId, $data['role_id'] ?? null);

        $user = DB::transaction(function () use ($businessId, $data, $roleId): User {
            $user = User::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'branch' => $data['branch'] ?? null,
                'status' => $data['status'] ?? User::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
                'password' => $data['password'],
            ]);

            $user->roles()->sync([$roleId]);

            return $user;
        });

        return $this->find($businessId, (int) $user->getKey());
    }

    /**
     * Update a staff account.
     *
     * `business_id` is never part of `$data`, so a user can never be moved to
     * another tenant. An empty password on an edit means "keep the current one",
     * which is what the form's "leave blank to keep" hint promises.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the role does not belong to the business.
     */
    public function update(int $businessId, User $user, array $data): User
    {
        $attributes = [];

        foreach (['name', 'email', 'phone', 'branch', 'status', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $attributes['password'] = $data['password'];
        }

        $roleId = array_key_exists('role_id', $data)
            ? $this->assertRoleBelongsToBusiness($businessId, $data['role_id'])
            : null;

        DB::transaction(function () use ($user, $attributes, $roleId): void {
            if ($attributes !== []) {
                $user->fill($attributes)->save();
            }

            if ($roleId !== null) {
                $user->roles()->sync([$roleId]);
            }
        });

        return $this->find($businessId, (int) $user->getKey());
    }

    /**
     * Base query: one tenant, with the role relation pre-loaded.
     *
     * @return Builder<User>
     */
    private function query(int $businessId): Builder
    {
        return User::query()
            ->where('business_id', $businessId)
            ->with('roles');
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function applyOrdering(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'desc'
            ? 'desc'
            : 'asc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    /**
     * Page size, clamped to the documented maximum.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * Validate that the requested role belongs to the business.
     *
     * The message never distinguishes "does not exist" from "belongs to another
     * tenant", which avoids confirming the existence of another tenant's role.
     *
     * @throws ValidationException
     */
    private function assertRoleBelongsToBusiness(int $businessId, mixed $roleId): int
    {
        $roleId = (int) $roleId;

        $owned = Role::query()
            ->forBusiness($businessId)
            ->whereKey($roleId)
            ->exists();

        if (! $owned) {
            throw ValidationException::withMessages([
                'role_id' => ['The selected role is invalid.'],
            ]);
        }

        return $roleId;
    }
}