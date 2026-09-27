<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\IndexExpenseRequest;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Traits\ApiResponse;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(IndexExpenseRequest $request): JsonResponse
    {
        return $this->paginated($this->expenses->paginate($this->businessId($request), $request->filters()), 'Expenses retrieved successfully', ExpenseResource::class);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->create($this->businessId($request), $request->user()?->getKey(), $request->validated());
        return $this->success(new ExpenseResource($expense), 'Expense created successfully', 201);
    }

    public function show(Request $request, string $expense): JsonResponse
    {
        return $this->success(new ExpenseResource($this->expenses->find($this->businessId($request), (int) $expense)), 'Expense retrieved successfully');
    }

    public function update(UpdateExpenseRequest $request, string $expense): JsonResponse
    {
        $found = $this->expenses->find($this->businessId($request), (int) $expense);
        $updated = $this->expenses->update($found, $request->validated(), $request->user()?->getKey());

        return $this->success(new ExpenseResource($updated), 'Expense updated successfully');
    }

    public function destroy(Request $request, string $expense): JsonResponse
    {
        $this->expenses->delete($this->expenses->find($this->businessId($request), (int) $expense));
        return $this->success(null, 'Expense deleted successfully');
    }

    private function businessId(Request $request): int { return (int) $request->user()?->business_id; }
}
