<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\IndexCategoryRequest;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Traits\ApiResponse;
use App\Services\Category\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Category CRUD endpoints.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET    /api/v1/categories
 *  - POST   /api/v1/categories
 *  - GET    /api/v1/categories/{category}
 *  - PUT    /api/v1/categories/{category}
 *  - PATCH  /api/v1/categories/{category}
 *  - DELETE /api/v1/categories/{category}
 *
 * Thin by design: Form Requests validate, CategoryService owns the business
 * logic and tenant scoping, and the standard envelope comes from the
 * {@see ApiResponse} trait.
 *
 * Route parameters are deliberately handled as raw ids (no implicit route
 * model binding): every lookup goes through
 * {@see CategoryService::find()} with the authenticated business, so a category
 * belonging to another tenant produces a plain 404 instead of leaking its
 * existence through binding behaviour.
 */
class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categories,
    ) {}

    /**
     * Paginated, tenant-scoped category list (search, status filter, sorting).
     */
    public function index(IndexCategoryRequest $request): JsonResponse
    {
        $paginator = $this->categories->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Categories retrieved successfully', CategoryResource::class);
    }

    /**
     * Create a category for the authenticated user's business.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create(
            $this->businessId($request),
            $request->validated(),
        );

        return $this->success(new CategoryResource($category), 'Category created successfully', 201);
    }

    /**
     * Fetch one category inside the authenticated business.
     */
    public function show(Request $request, string $category): JsonResponse
    {
        $found = $this->categories->find($this->businessId($request), (int) $category);

        return $this->success(new CategoryResource($found), 'Category retrieved successfully');
    }

    /**
     * Update the editable master data of a category (PUT and PATCH).
     */
    public function update(UpdateCategoryRequest $request, string $category): JsonResponse
    {
        $found = $this->categories->find($this->businessId($request), (int) $category);

        $updated = $this->categories->update($found, $request->validated());

        return $this->success(new CategoryResource($updated), 'Category updated successfully');
    }

    /**
     * Soft-delete a category that is not referenced by any product.
     *
     * A category still in use produces a 409 business error from the service
     * (rendered by the API exception handler), never a silent cascade.
     */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $found = $this->categories->find($this->businessId($request), (int) $category);

        $this->categories->delete($found);

        return $this->success(null, 'Category deleted successfully');
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
