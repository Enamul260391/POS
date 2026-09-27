<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportFilterRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only business-scoped reporting endpoints.
 *
 * Reports return explicit period/metric arrays because aggregate reports do
 * not have a single resource representation. The authenticated user's business
 * is the only tenant scope accepted; client `business_id` is never read.
 */
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $reports) {}

    public function sales(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->sales($this->businessId($request), $request->filters()),
            'Sales report generated successfully',
        );
    }

    public function purchases(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->purchases($this->businessId($request), $request->filters()),
            'Purchase report generated successfully',
        );
    }

    public function expenses(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->expenses($this->businessId($request), $request->filters()),
            'Expense report generated successfully',
        );
    }

    public function profitLoss(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->profitLoss($this->businessId($request), $request->filters()),
            'Profit/loss report generated successfully',
        );
    }

    public function inventory(ReportFilterRequest $request): JsonResponse
    {
        // Inventory is a current snapshot. We still validate the request so
        // accidental unsupported filters are rejected consistently.
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return $this->success(
            $this->reports->inventory($this->businessId($request)),
            'Inventory report generated successfully',
        );
    }

    public function dashboard(ReportFilterRequest $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return $this->success(
            $this->reports->dashboard($this->businessId($request), (int) $request->user()?->getKey()),
            'Dashboard summary generated successfully',
        );
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
