<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRole\SyncUserRolesRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Services\UserRole\UserRoleService;
use Illuminate\Http\JsonResponse;

/**
 * Assigns roles to a user.
 *
 * Endpoints (behind `auth:sanctum` + a `can:` permission check):
 *  - PUT   /api/v1/users/{user}/roles   requires `users.roles.update`
 *  - PATCH /api/v1/users/{user}/roles   requires `users.roles.update`
 *
 * Both verbs replace the full set of roles (sync semantics) rather than adding
 * to it, so the endpoint is idempotent and a client never has to diff state.
 *
 * The route parameter is a raw id: `UserRoleService::findUser()` resolves it
 * inside the authenticated business, so another tenant's user is a plain 404.
 */
class UserRoleController extends Controller
{
    public function __construct(
        private readonly UserRoleService $userRoles,
    ) {}

    /**
     * Replace the roles held by the target user.
     */
    public function sync(SyncUserRolesRequest $request, string $user): JsonResponse
    {
        $businessId = (int) $request->user()?->business_id;

        $target = $this->userRoles->findUser($businessId, (int) $user);

        $updated = $this->userRoles->syncRoles($businessId, $target, $request->roleIds());

        return $this->success([
            'user' => new UserResource($updated),
            'roles' => RoleResource::collection($updated->roles),
        ], 'User roles updated successfully');
    }
}
