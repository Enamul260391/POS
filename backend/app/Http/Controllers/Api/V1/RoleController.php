<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\IndexRoleRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Role\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Role CRUD endpoints.
 *
 * Endpoints (all behind `auth:sanctum` + a `can:` permission check):
 *  - GET    /api/v1/roles          requires `roles.view`
 *  - POST   /api/v1/roles          requires `roles.create`
 *  - GET    /api/v1/roles/{role}   requires `roles.view`
 *  - PUT    /api/v1/roles/{role}   requires `roles.update`
 *  - PATCH  /api/v1/roles/{role}   requires `roles.update`
 *  - DELETE /api/v1/roles/{role}   requires `roles.delete`
 *
 * Thin by design: Form Requests validate, RoleService owns the business logic,
 * tenant scoping and transactions, and the standard envelope comes from the
 * {@see ApiResponse} trait.
 *
 * Route parameters are handled as raw ids (no implicit route model binding):
 * every lookup goes through {@see RoleService::find()} with the authenticated
 * business, so another tenant's role is a plain 404 rather than a record that
 * binding would happily hand to the controller.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
    ) {}

    /**
     * Paginated, tenant-scoped role list (search, sorting, pagination).
     */
    public function index(IndexRoleRequest $request): JsonResponse
    {
        $paginator = $this->roles->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Roles retrieved successfully', RoleResource::class);
    }

    /**
     * Create a role for the authenticated user's business.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create(
            $this->businessId($request),
            $request->validated(),
        );

        return $this->success(new RoleResource($role), 'Role created successfully', 201);
    }

    /**
     * Fetch one role inside the authenticated business.
     */
    public function show(Request $request, string $role): JsonResponse
    {
        $found = $this->roles->find($this->businessId($request), (int) $role);

        return $this->success(new RoleResource($found), 'Role retrieved successfully');
    }

    /**
     * Update a role and, when supplied, its permission grants (PUT and PATCH).
     */
    public function update(UpdateRoleRequest $request, string $role): JsonResponse
    {
        $found = $this->roles->find($this->businessId($request), (int) $role);

        $updated = $this->roles->update($found, $request->validated());

        return $this->success(new RoleResource($updated), 'Role updated successfully');
    }

    /**
     * Delete an unassigned role.
     *
     * A role still held by users produces a 409 business error from the service
     * (rendered by the API exception handler), never a silent de-authorisation.
     */
    public function destroy(Request $request, string $role): JsonResponse
    {
        $found = $this->roles->find($this->businessId($request), (int) $role);

        $this->roles->delete($found);

        return $this->success(null, 'Role deleted successfully');
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload or the query string; the
     * route sits behind `auth:sanctum`, so a user is always present.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
