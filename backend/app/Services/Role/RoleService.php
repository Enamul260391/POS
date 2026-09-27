<?php

declare(strict_types=1);

namespace App\Services\Role;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Services\Permission\PermissionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Business logic for roles, including their permission grants.
 *
 * Every read and write is resolved through {@see Role::scopeForBusiness()}, so
 * a role owned by another tenant is indistinguishable from one that does not
 * exist. The owning `business_id` is passed in by the controller and always
 * originates from the authenticated user — a client-supplied `business_id` is
 * never read.
 *
 * Roles are hard-deleted (no soft deletes on the table). `permission_role` and
 * `role_user` both cascade on delete, so pivots cannot be orphaned, but a role
 * that is still assigned to users is refused with a 409 rather than silently
 * stripping authorisations away from live accounts.
 */
final class RoleService
{
    /** Message returned when an assigned role is deleted. */
    public const IN_USE_DELETE_MESSAGE = 'Cannot delete role because it is assigned to one or more users.';

    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = ['name', 'slug', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Paginated, tenant-scoped role list.
     *
     * Permissions are eager-loaded because {@see RoleResource} includes them,
     * and `users_count` is a single correlated sub-query — the list stays a
     * fixed number of queries with no N+1.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Role>
     */
    public function paginate(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = Role::query()
            ->forBusiness($businessId)
            ->with('permissions')
            ->withCount('users');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->search($search);
        }

        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'desc'
            ? 'desc'
            : 'asc';

        $perPage = max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage);
    }

    /**
     * Resolve one role inside the tenant, or fail with a 404 envelope.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $businessId, int $roleId): Role
    {
        return $this->query($businessId)->whereKey($roleId)->firstOrFail();
    }

    /**
     * Create a role for the given business and attach its permissions.
     *
     * The role row and the pivot rows are written in one transaction, so a role
     * is never persisted without (or with a partial set of) its grants.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): Role
    {
        $permissionIds = $this->assertPermissionsExist($data['permission_ids'] ?? []);

        return DB::transaction(function () use ($businessId, $data, $permissionIds): Role {
            $role = Role::create([
                // Ownership comes from the caller (the authenticated user), never
                // from the payload.
                'business_id' => $businessId,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($businessId, (string) $data['name']),
                'description' => $data['description'] ?? null,
            ]);

            $this->permissions->syncToRole($role, $permissionIds);

            return $this->find($businessId, (int) $role->getKey());
        });
    }

    /**
     * Update a role and, when supplied, replace its permission grants.
     *
     * Attributes and pivot rows share one transaction: a failed permission sync
     * rolls the rename back as well.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        $businessId = (int) $role->business_id;

        $permissionIds = array_key_exists('permission_ids', $data)
            ? $this->assertPermissionsExist($data['permission_ids'] ?? [])
            : null;

        return DB::transaction(function () use ($role, $data, $businessId, $permissionIds): Role {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];

                // Keep the canonical identifier aligned with the name, without
                // churning the slug when only the description changes.
                $attributes['slug'] = $this->uniqueSlug($businessId, (string) $data['name'], $role);
            }

            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }

            if ($attributes !== []) {
                $role->fill($attributes)->save();
            }

            if ($permissionIds !== null) {
                $this->permissions->syncToRole($role, $permissionIds);
            }

            return $this->find($businessId, (int) $role->getKey());
        });
    }

    /**
     * Delete a role that is not assigned to any user.
     *
     * Deleting a role strips authorisation from every account holding it, which
     * is a silent, data-integrity-affecting side effect. The caller therefore
     * receives a 409 business error and must remove the assignments first. Once
     * the role is unused, `role_user` / `permission_role` cascade on delete
     * (verified in the pivot migrations), so no orphan rows can survive.
     *
     * @throws BusinessRuleException when the role is still assigned to a user.
     */
    public function delete(Role $role): void
    {
        if ($role->users()->exists()) {
            throw new BusinessRuleException(self::IN_USE_DELETE_MESSAGE);
        }

        $role->delete();
    }

    /**
     * Base query: one tenant, with grants and the user count pre-loaded.
     *
     * @return Builder<Role>
     */
    private function query(int $businessId): Builder
    {
        return Role::query()
            ->forBusiness($businessId)
            ->with('permissions')
            ->withCount('users');
    }

    /**
     * Validate the requested permission ids.
     *
     * Permissions are a global catalogue, so existence is the only check needed
     * — there is no owning business to compare against. Any unknown id is
     * rejected (as a 422 validation failure, consistent with the rest of the
     * API) *before* anything is written, so a bad id can never leave a
     * half-applied role behind.
     *
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function assertPermissionsExist(mixed $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        $existing = $this->permissions->existingIds($ids);

        $missing = array_values(array_diff($ids, $existing));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permission_ids' => [
                    'The following permission ids do not exist: '.implode(', ', $missing).'.',
                ],
            ]);
        }

        return $existing;
    }

    /**
     * Canonical slug for a role name, unique inside the business.
     *
     * `roles` has a unique `(business_id, slug)` index, so the slug is derived
     * deterministically and de-duplicated in application code — a raw database
     * collision would surface as a 500 instead of a clean 422.
     */
    private function uniqueSlug(int $businessId, string $name, ?Role $ignore = null): string
    {
        $base = Str::slug($name);
        $base = substr($base === '' ? 'role' : $base, 0, Role::MAX_SLUG_LENGTH);

        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($businessId, $slug, $ignore)) {
            $suffixPart = '-'.$suffix;
            $slug = substr($base, 0, Role::MAX_SLUG_LENGTH - strlen($suffixPart)).$suffixPart;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Is the slug already used by another role in this business?
     */
    private function slugTaken(int $businessId, string $slug, ?Role $ignore = null): bool
    {
        $query = Role::query()
            ->forBusiness($businessId)
            ->where('slug', $slug);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        return $query->exists();
    }
}
