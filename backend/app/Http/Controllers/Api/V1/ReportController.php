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

    /**
     * Who owes whom, right now, per customer.
     *
     * A dedicated endpoint rather than another field on `sales()`, because this
     * is a *position* report and `sales()` is a *period* report: the balance is
     * the ledger's whole history and must not change because the client moved
     * the date filter, which is precisely the property a field on the sales
     * report could not honestly carry. Only the `period_movement` figure is
     * windowed, and it is named so it can never be read as a balance.
     *
     * `ReportFilterRequest` is reused unchanged, so `customer_id` is validated
     * against this business exactly as it is everywhere else — a foreign id is a
     * 422 on the established rule, not a new authorization path.
     */
    public function customerOutstanding(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->customerOutstandingReport($this->businessId($request), $request->filters()),
            'Customer outstanding report generated successfully',
        );
    }

    /**
     * Who owes whom, right now, per supplier.
     *
     * The payables mirror of {@see customerOutstanding()}, and for exactly the
     * same reason they are a separate endpoint rather than another field on
     * `purchases()`: the balance is a standing position over the whole history
     * and must not change because the client moved the date filter, which is
     * precisely the property a field on a *period* report could not honestly
     * carry. Only `period_movement` is windowed, and it is named so it can never
     * be read as a balance.
     *
     * `ReportFilterRequest` is reused unchanged, so `supplier_id` is validated
     * against this business exactly as it is everywhere else — a foreign id is a
     * 422 on the established rule, not a new authorization path.
     */
    public function supplierOutstanding(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->supplierOutstandingReport($this->businessId($request), $request->filters()),
            'Supplier outstanding report generated successfully',
        );
    }

    /**
     * What actually moved through the cash drawers in the period.
     *
     * A **period activity** report, unlike the Stage 7/8 outstanding reports: the
     * requested window genuinely restricts which movements are counted, because a
     * cash-register movement is an dated event rather than a standing balance.
     * `ReportDateRange` therefore decides the population here, exactly as it does
     * on the sales report.
     *
     * `ReportFilterRequest` is reused unchanged apart from two added,
     * conventionally-validated filters — `cash_register_id` (same-business only,
     * so a foreign id is a 422 on the established rule) and `transaction_type`
     * (canonical `CashRegisterTransaction::TYPES` only, so an unknown type is
     * refused rather than silently matching nothing). Neither introduces a new
     * authorization path, and `reports.view` remains the whole permission.
     *
     * The source of truth is `cash_register_transactions`; nothing here is
     * re-derived from sales, payments, expenses or returns. See
     * {@see ReportService::cashRegisterMovements()}.
     */
    public function cashRegisterMovements(ReportFilterRequest $request): JsonResponse
    {
        return $this->success(
            $this->reports->cashRegisterMovements($this->businessId($request), $request->filters()),
            'Cash register movement report generated successfully',
        );
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
