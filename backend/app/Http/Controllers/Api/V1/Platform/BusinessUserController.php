<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\BusinessUser\IndexBusinessUserRequest;
use App\Http\Requests\Platform\BusinessUser\StoreBusinessUserRequest;
use App\Http\Requests\Platform\BusinessUser\SyncBusinessUserRolesRequest;
use App\Http\Requests\Platform\BusinessUser\UpdateBusinessUserRequest;
use App\Http\Requests\Platform\BusinessUser\UpdateBusinessUserStatusRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserDirectoryResource;
use App\Http\Resources\UserResource;
use App\Services\Platform\PlatformUserService;
use Illuminate\Http\JsonResponse;

/**
 * Platform business-user (staff account) management endpoints.
 *
 * All behind `auth:sanctum` + `active` + `platform`, each with its own
 * `platform.users.*` Gate. Route params are raw ids: {business} resolves
 * through the service (missing/deleted is 404) and every {user} resolves
 * inside that business, so another tenant's user is a plain 404.
 */
class BusinessUserController extends Controller
{
    public function __construct(
        private readonly PlatformUserService $platformUsers,
    ) {}

    /**
     * Paginated, business-scoped staff list.
     */
    public function index(IndexBusinessUserRequest $request, string $business): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $paginator = $this->platformUsers->paginate((int) $businessId, $request->filters());

        return $this->paginated($paginator, 'Users retrieved successfully', UserDirectoryResource::class);
    }

    /**
     * Create a staff account inside the route business.
     */
    public function store(StoreBusinessUserRequest $request, string $business): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $user = $this->platformUsers->create((int) $businessId, $request->validated());

        return $this->success(new UserDirectoryResource($user), 'User created successfully', 201);
    }

    /**
     * Fetch one user inside the route business.
     */
    public function show(string $business, string $user): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $found = $this->platformUsers->find((int) $businessId, (int) $user);

        return $this->success(new UserDirectoryResource($found), 'User retrieved successfully');
    }

    /**
     * Update a staff account inside the route business (PUT and PATCH).
     */
    public function update(UpdateBusinessUserRequest $request, string $business, string $user): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $found = $this->platformUsers->find((int) $businessId, (int) $user);

        $updated = $this->platformUsers->update((int) $businessId, $found, $request->validated());

        return $this->success(new UserDirectoryResource($updated), 'User updated successfully');
    }

    /**
     * Activate or deactivate a staff account (PATCH .../status).
     */
    public function updateStatus(UpdateBusinessUserStatusRequest $request, string $business, string $user): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $found = $this->platformUsers->find((int) $businessId, (int) $user);

        $updated = $this->platformUsers->setStatus((int) $businessId, $found, (string) $request->validated()['status']);

        return $this->success(new UserDirectoryResource($updated), 'User status updated successfully');
    }

    /**
     * Replace the roles held by a staff account (PUT/PATCH, sync semantics).
     */
    public function syncRoles(SyncBusinessUserRolesRequest $request, string $business, string $user): JsonResponse
    {
        $businessId = $this->platformUsers->business((int) $business)->getKey();

        $target = $this->platformUsers->find((int) $businessId, (int) $user);

        $updated = $this->platformUsers->syncRoles((int) $businessId, $target, $request->roleIds());

        return $this->success([
            'user' => new UserResource($updated),
            'roles' => RoleResource::collection($updated->roles),
        ], 'User roles updated successfully');
    }

    /**
     * Delete a staff account with no transactional history (409 otherwise).
     */
    public function destroy(string $business, string $user): JsonResponse
    {
        $resolvedBusiness = $this->platformUsers->business((int) $business);

        $found = $this->platformUsers->find((int) $resolvedBusiness->getKey(), (int) $user);

        $this->platformUsers->delete($resolvedBusiness, $found);

        return $this->success(null, 'User deleted successfully');
    }
}
