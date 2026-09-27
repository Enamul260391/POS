<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\IndexPermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Services\Permission\PermissionService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only access to the global permission catalogue.
 *
 * Endpoints (behind `auth:sanctum` + a `can:` permission check):
 *  - GET /api/v1/permissions   requires `permissions.view`
 *
 * There is deliberately no create/update/delete. The `permissions` table has no
 * `business_id` and is populated by `RolesAndPermissionsSeeder`, so permissions
 * are predefined system capabilities; businesses choose which of them to grant
 * to their own roles through the role endpoints. Exposing CRUD would let any
 * caller mutate a global catalogue, which is not a tenant-level concern.
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Paginated permission catalogue with search.
     *
     * The catalogue is global, so the response is identical for every business
     * and needs no tenant scoping.
     */
    public function index(IndexPermissionRequest $request): JsonResponse
    {
        $paginator = $this->permissions->paginate($request->filters());

        return $this->paginated($paginator, 'Permissions retrieved successfully', PermissionResource::class);
    }
}
