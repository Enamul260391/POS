<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\IndexCustomerRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\Customer\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer CRUD endpoints.
 *
 * Endpoints (all behind `auth:sanctum`):
 *  - GET    /api/v1/customers
 *  - POST   /api/v1/customers
 *  - GET    /api/v1/customers/{customer}
 *  - PUT    /api/v1/customers/{customer}
 *  - PATCH  /api/v1/customers/{customer}
 *  - DELETE /api/v1/customers/{customer}
 *
 * Thin by design: Form Requests validate, CustomerService owns the business
 * logic and tenant scoping, and the standard envelope comes from the
 * {@see ApiResponse} trait.
 *
 * Route parameters are deliberately handled as raw ids (no implicit route
 * model binding): every lookup goes through
 * {@see CustomerService::find()} with the authenticated business, so a customer
 * belonging to another tenant produces a plain 404 instead of leaking its
 * existence through binding behaviour.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    /**
     * Paginated, tenant-scoped customer list (search, status filter, sorting).
     */
    public function index(IndexCustomerRequest $request): JsonResponse
    {
        $paginator = $this->customers->paginate(
            $this->businessId($request),
            $request->filters(),
        );

        return $this->paginated($paginator, 'Customers retrieved successfully', CustomerResource::class);
    }

    /**
     * Create a customer for the authenticated user's business.
     *
     * `business_id` is never read from the payload.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create(
            $this->businessId($request),
            $request->validated(),
        );

        return $this->success(new CustomerResource($customer), 'Customer created successfully', 201);
    }

    /**
     * Fetch one customer inside the authenticated business.
     */
    public function show(Request $request, string $customer): JsonResponse
    {
        $found = $this->customers->find($this->businessId($request), (int) $customer);

        return $this->success(new CustomerResource($found), 'Customer retrieved successfully');
    }

    /**
     * Update the editable master data of a customer (PUT and PATCH).
     *
     * `business_id` is not part of the validated payload, so posting it
     * changes nothing.
     */
    public function update(UpdateCustomerRequest $request, string $customer): JsonResponse
    {
        $found = $this->customers->find($this->businessId($request), (int) $customer);

        $updated = $this->customers->update($found, $request->validated());

        return $this->success(new CustomerResource($updated), 'Customer updated successfully');
    }

    /**
     * Soft-delete a customer that no sale or sale return references.
     *
     * A customer still in use produces a 409 business error from the service
     * (rendered by the API exception handler); historical records are never
     * destroyed.
     */
    public function destroy(Request $request, string $customer): JsonResponse
    {
        $found = $this->customers->find($this->businessId($request), (int) $customer);

        $this->customers->delete($found);

        return $this->success(null, 'Customer deleted successfully');
    }

    /**
     * Tenant scope for this request: the authenticated user's business.
     *
     * `business_id` is never read from the payload or the query string.
     */
    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}