<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPermission\StoreUserPermissionOverrideRequest;
use App\Http\Requests\UserPermission\SyncUserPermissionOverridesRequest;
use App\Http\Resources\UserPermissionOverrideResource;
use App\Models\User;
use App\Services\UserPermission\UserPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the permission overrides (`allow` / `deny`) of a single user.
 *
 * Endpoints (all behind `auth:sanctum` + `active` + a `can:` permission check):
 *  - GET    /api/v1/users/{user}/permission-overrides
 *  - POST   /api/v1/users/{user}/permission-overrides
 *  - DELETE /api/v1/users/{user}/permission-overrides/{permission}
 *  - PUT    /api/v1/users/{user}/permission-overrides   (full replace)
 *  - PATCH  /api/v1/users/{user}/permission-overrides   (full replace)
 *
 * Authorization uses the existing `users.roles.update` capability, which is what
 * guards the sibling `/users/{user}/roles` endpoint. Managing a user's overrides
 * *is* managing that user's authorisation, so reusing it keeps one capability for
 * the whole "who may this account do" surface — and introduces no new permission
 * slug.
 *
 * Route parameters are raw ids (no implicit route model binding): the target
 * user is resolved through `UserPermissionService::findUser()` with the
 * authenticated business, so another tenant's user is a plain 404. The
 * `permission` parameter is a global catalogue id and is validated by the service
 * against `permissions`.
 *
 * This controller manages `user ↔ permission` overrides only. Role ↔ permission
 * synchronisation stays in `RoleController` and role assignment in
 * `UserRoleController`; `permission_role` and `role_user` are never touched here.
 */
class UserPermissionController extends Controller
{
    public function __construct(
        private readonly UserPermissionService $overrides,
    ) {}

    /**
     * List the target user's stored overrides.
     */
    public function index(Request $request, string $user): JsonResponse
    {
        $actor = $this->actor($request);

        $target = $this->target($request, $user);

        $this->overrides->assertMayManage($actor, $target);

        return $this->success([
            'user_id' => (int) $target->getKey(),
            'items' => UserPermissionOverrideResource::collection(
                $this->overrides->overridesFor($target)
            ),
        ], 'User permission overrides retrieved successfully');
    }

    /**
     * Create or update one override for the target user.
     */
    public function store(StoreUserPermissionOverrideRequest $request, string $user): JsonResponse
    {
        $target = $this->target($request, $user);

        $items = $this->overrides->upsert(
            $this->businessId($request),
            $this->actor($request),
            $target,
            $request->permissionId(),
            $request->effect(),
        );

        return $this->success([
            'user_id' => (int) $target->getKey(),
            'items' => UserPermissionOverrideResource::collection($items),
        ], 'User permission override saved successfully', 201);
    }

    /**
     * Remove one override (`user` + `permission`).
     */
    public function destroy(Request $request, string $user, string $permission): JsonResponse
    {
        $target = $this->target($request, $user);

        $this->overrides->delete(
            $this->businessId($request),
            $this->actor($request),
            $target,
            (int) $permission,
        );

        return $this->success(null, 'User permission override deleted successfully');
    }

    /**
     * Replace the target user's complete override set (PUT and PATCH).
     */
    public function sync(SyncUserPermissionOverridesRequest $request, string $user): JsonResponse
    {
        $target = $this->target($request, $user);

        $items = $this->overrides->sync(
            $this->businessId($request),
            $this->actor($request),
            $target,
            $request->overrides(),
        );

        return $this->success([
            'user_id' => (int) $target->getKey(),
            'items' => UserPermissionOverrideResource::collection($items),
        ], 'User permission overrides updated successfully');
    }

    /**
     * The authenticated user, resolved behind `auth:sanctum`.
     */
    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /**
     * Resolve the route's user id inside the authenticated business.
     *
     * A user of another tenant raises `ModelNotFoundException`, rendered as the
     * standard 404 envelope, so the response never confirms that it exists.
     */
    private function target(Request $request, string $user): User
    {
        return $this->overrides->findUser($this->businessId($request), (int) $user);
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload or the query string, so a
     * client cannot redirect an override into another tenant.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
