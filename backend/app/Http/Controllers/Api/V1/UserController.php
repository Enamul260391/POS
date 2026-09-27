<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserDirectoryResource;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff directory endpoints.
 *
 * Endpoints (all behind `auth:sanctum` + a `can:` permission check):
 *  - GET    /api/v1/users
 *  - POST   /api/v1/users
 *  - GET    /api/v1/users/{user}
 *  - PUT    /api/v1/users/{user}
 *  - PATCH  /api/v1/users/{user}
 *
 * Thin by design: Form Requests validate, {@see UserService} owns the business
 * logic and tenant scoping, and the standard envelope comes from the
 * {@see \App\Http\Traits\ApiResponse} trait.
 *
 * Route parameters are deliberately handled as raw ids (no implicit route
 * model binding): every lookup goes through `UserService::find()` with the
 * authenticated business, so a user belonging to another tenant produces a
 * plain 404 instead of leaking its existence through binding behaviour.
 *
 * Delete is deliberately absent: removing a staff account would orphan every
 * sale, purchase and cash-register row that references it, so it needs the
 * deactivation flow (`status`) rather than a hard delete.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * Paginated, tenant-scoped staff list (search, status/role filters, sorting).
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        $paginator = $this->users->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated(
            $paginator,
            'Users retrieved successfully',
            UserDirectoryResource::class,
        );
    }

    /**
     * Create a staff account for the authenticated user's business.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create(
            $this->businessId($request),
            $request->validated(),
        );

        return $this->success(
            new UserDirectoryResource($user),
            'User created successfully',
            201,
        );
    }

    /**
     * Fetch one user inside the authenticated business.
     */
    public function show(Request $request, string $user): JsonResponse
    {
        $found = $this->users->find($this->businessId($request), (int) $user);

        return $this->success(new UserDirectoryResource($found), 'User retrieved successfully');
    }

    /**
     * Update a staff account (PUT and PATCH).
     */
    public function update(UpdateUserRequest $request, string $user): JsonResponse
    {
        $businessId = $this->businessId($request);

        $found = $this->users->find($businessId, (int) $user);

        $updated = $this->users->update($businessId, $found, $request->validated());

        return $this->success(new UserDirectoryResource($updated), 'User updated successfully');
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload or the query string. The
     * route sits behind `auth:sanctum`, so a user is always present; the
     * null-safe fallback keeps static analysis honest and, in the impossible
     * missing-user case, simply scopes to a business id that matches nothing.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}