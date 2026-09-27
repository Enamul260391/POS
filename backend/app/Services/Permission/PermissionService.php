<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single source of truth for "what may a user do?".
 *
 * Permissions are a **global** catalogue: the `permissions` table has no
 * `business_id`, so a permission is a system-wide definition (`roles.update`)
 * and a business grants a subset of it to its own roles. Because of that this
 * service exposes no create/update/delete — the catalogue is seeded
 * (`RolesAndPermissionsSeeder`) and read-only through the API.
 *
 * ## Naming
 * The API authorises against the fine-grained capability names below
 * (`roles.view`, `roles.create`, `roles.update`, `roles.delete`,
 * `permissions.view`, `users.roles.update`, `settings.view`, `settings.update`).
 * The existing seeded catalogue uses the coarser `<resource>.manage` convention
 * (`roles.manage`, `users.manage`, `settings.manage`), and those rows already
 * exist in installations. Rather than inventing a second naming system or
 * forcing a reseed, each capability accepts a set of equivalent slugs: the
 * specific one **or** the coarser `*.manage` permission for the same resource.
 * A business that seeds the finer catalogue gets exact per-action control; one
 * that still has the original `*.manage` rows keeps working unchanged.
 *
 * Every check runs against the slugs held through the user's roles and is
 * memoised on the user instance, so N checks cost at most one query.
 */
final class PermissionService
{
    /** Read the role catalogue. */
    public const ROLES_VIEW = 'roles.view';

    /** Create roles. */
    public const ROLES_CREATE = 'roles.create';

    /** Edit roles and their permission grants. */
    public const ROLES_UPDATE = 'roles.update';

    /** Delete roles. */
    public const ROLES_DELETE = 'roles.delete';

    /** Read the global permission catalogue. */
    public const PERMISSIONS_VIEW = 'permissions.view';

    /** Change which roles a user holds. */
    public const USERS_ROLES_UPDATE = 'users.roles.update';

    /** Read business settings. */
    public const SETTINGS_VIEW = 'settings.view';

    /** Change business settings. */
    public const SETTINGS_UPDATE = 'settings.update';

    /**
     * Accepted slug aliases per capability.
     *
     * The first entry is the canonical, fine-grained slug; the rest are
     * compatibility aliases matching the pre-existing seeded convention.
     *
     * @var array<string, array<int, string>>
     */
    public const CAPABILITIES = [
        self::ROLES_VIEW => [self::ROLES_VIEW, 'roles.manage'],
        self::ROLES_CREATE => [self::ROLES_CREATE, 'roles.manage'],
        self::ROLES_UPDATE => [self::ROLES_UPDATE, 'roles.manage'],
        self::ROLES_DELETE => [self::ROLES_DELETE, 'roles.manage'],
        self::PERMISSIONS_VIEW => [self::PERMISSIONS_VIEW, 'roles.manage'],
        self::USERS_ROLES_UPDATE => [self::USERS_ROLES_UPDATE, 'users.manage', 'roles.manage'],
        self::SETTINGS_VIEW => [self::SETTINGS_VIEW, 'settings.manage'],
        self::SETTINGS_UPDATE => [self::SETTINGS_UPDATE, 'settings.manage'],
    ];

    /** Every capability name, in a stable order (used for docs and tests). */
    public const ALL_CAPABILITIES = [
        self::ROLES_VIEW,
        self::ROLES_CREATE,
        self::ROLES_UPDATE,
        self::ROLES_DELETE,
        self::PERMISSIONS_VIEW,
        self::USERS_ROLES_UPDATE,
        self::SETTINGS_VIEW,
        self::SETTINGS_UPDATE,
    ];

    /** Columns a client may sort the catalogue by. */
    public const SORTABLE_COLUMNS = ['name', 'slug', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Slug aliases that satisfy the given capability.
     *
     * @return array<int, string>
     */
    public static function slugsFor(string $capability): array
    {
        return self::CAPABILITIES[$capability] ?? [$capability];
    }

    /**
     * Is the capability a known one?
     *
     * Guards against a typo silently creating an un-grantable permission.
     */
    public static function isKnown(string $capability): bool
    {
        return array_key_exists($capability, self::CAPABILITIES);
    }

    /**
     * Does the user satisfy the capability?
     *
     * Used by the Gate definition in `AppServiceProvider`, which is in turn used
     * by route middleware, `$user->can()` and `$user->hasPermission()`.
     */
    public function grants(User $user, string $capability): bool
    {
        $held = $user->permissionSlugs();

        foreach (self::slugsFor($capability) as $slug) {
            if (in_array($slug, $held, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paginated permission catalogue with search.
     *
     * The catalogue is global, so no tenant scoping applies here.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Permission>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Permission::query();

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
     * Resolve existing permission ids in a single query.
     *
     * Permissions are global, so only existence has to be checked — there is no
     * ownership to verify. Returns the ids that actually exist, letting the
     * caller compare against the requested set and reject anything unknown
     * before the pivot table is touched.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Replace a role's permission grants.
     *
     * `sync()` is deliberate: `permission_role` carries a unique
     * `(permission_id, role_id)` index, so `sync()` can never create duplicate
     * pivot rows, and it detaches grants that are no longer requested.
     *
     * @param  array<int, int>  $ids
     */
    public function syncToRole(Role $role, array $ids): void
    {
        $role->permissions()->sync($ids);
    }
}
