<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategory\IndexExpenseCategoryRequest;
use App\Http\Requests\ExpenseCategory\StoreExpenseCategoryRequest;
use App\Http\Requests\ExpenseCategory\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Http\Traits\ApiResponse;
use App\Services\ExpenseCategory\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly ExpenseCategoryService $categories) {}

    public function index(IndexExpenseCategoryRequest $request): JsonResponse
    {
        return $this->paginated($this->categories->paginate($this->businessId($request), $request->filters()), 'Expense categories retrieved successfully', ExpenseCategoryResource::class);
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($this->businessId($request), $request->validated());
        return $this->success(new ExpenseCategoryResource($category), 'Expense category created successfully', 201);
    }

    public function show(Request $request, string $expenseCategory): JsonResponse
    {
        $category = $this->categories->find($this->businessId($request), (int) $expenseCategory);
        return $this->success(new ExpenseCategoryResource($category), 'Expense category retrieved successfully');
    }

    public function update(UpdateExpenseCategoryRequest $request, string $expenseCategory): JsonResponse
    {
        $category = $this->categories->find($this->businessId($request), (int) $expenseCategory);
        return $this->success(new ExpenseCategoryResource($this->categories->update($category, $request->validated())), 'Expense category updated successfully');
    }

    public function destroy(Request $request, string $expenseCategory): JsonResponse
    {
        $this->categories->delete($this->categories->find($this->businessId($request), (int) $expenseCategory));
        return $this->success(null, 'Expense category deleted successfully');
    }

    private function businessId(Request $request): int { return (int) $request->user()?->business_id; }
}
