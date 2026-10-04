"use client";

import { useCallback, useMemo, useState } from "react";

import { Button } from "@/components/ui/button";
import { CashRegisterMovementCard } from "@/features/reports/components/CashRegisterMovementCard";
import { CustomerOutstandingCard } from "@/features/reports/components/CustomerOutstandingCard";
import { DailySalesCard } from "@/features/reports/components/DailySalesCard";
import { ExpenseSummaryCard } from "@/features/reports/components/ExpenseSummaryCard";
import { LowStockCard } from "@/features/reports/components/LowStockCard";
import { MonthlySalesCard } from "@/features/reports/components/MonthlySalesCard";
import { ProfitSummaryCard } from "@/features/reports/components/ProfitSummaryCard";
import { PurchaseSummaryCard } from "@/features/reports/components/PurchaseSummaryCard";
import { ReportMetric } from "@/features/reports/components/ReportMetric";
import { ReportsDateFilter } from "@/features/reports/components/ReportsDateFilter";
import { SalesSummaryCard } from "@/features/reports/components/SalesSummaryCard";
import { SupplierOutstandingCard } from "@/features/reports/components/SupplierOutstandingCard";
import { TopCategoriesCard } from "@/features/reports/components/TopCategoriesCard";
import { TopCustomersCard } from "@/features/reports/components/TopCustomersCard";
import { TopProductsCard } from "@/features/reports/components/TopProductsCard";
import { TopUsersCard } from "@/features/reports/components/TopUsersCard";
import {
  resolveDateRange,
  toApiDate,
  type ReportDateFilter,
} from "@/features/reports/lib/reports";
import { loadReportsSnapshot } from "@/features/reports/services/reportService";
import { formatMoney } from "@/features/pos/lib/format";
import { useApiResource } from "@/lib/api/useApiResource";
import type { DashboardReport } from "@/types/report";

/**
 * Reports dashboard composition root — fully API-backed.
 *
 * Everything on this screen now comes from the Laravel reports API and nothing
 * else: there is no zustand store in the path, no `buildReports()` pass, and no
 * aggregate is computed in the browser. The previous local derivation over the
 * sales / purchases / expenses / products stores is gone entirely.
 *
 * How it is wired:
 *
 *   - the screen owns the single `ReportDateFilter`, resolves it to a window,
 *     and sends it as two `YYYY-MM-DD` parameters. `toApiDate` builds them from
 *     local calendar getters, so a day never shifts across timezones.
 *   - `useApiResource` runs the loader whenever the window changes, aborts the
 *     previous run, and exposes loading / refreshing / error / retry. It is the
 *     same hook every other API-backed screen uses, so the behaviour matches.
 *   - `loadReportsSnapshot` fetches all six report endpoints concurrently and
 *     hands back one mapped object, so a card is a pure function of the APIs
 *     own numbers.
 *
 * The sales trend cards, the top-products leaderboard, the sales-by-category
 * leaderboard, the sales-by-customer leaderboard and the sales-by-staff
 * leaderboard read `metrics.daily_series`, `metrics.monthly_series`,
 * `metrics.top_products`, `metrics.top_categories`, `metrics.top_customers` and
 * `metrics.top_users` off the sales response this screen already loads, so they
 * issue no request of their own. The only card still showing an "unavailable"
 * panel is the low-stock item list — see `REPORT_UNAVAILABLE_REASONS` in
 * `@/types/report`.
 *
 * The customer outstanding card is the one report on this screen that has an
 * endpoint of its own: it is a **position** report (who owes whom *now*) rather
 * than a period one, so its balances come from `customer_ledger_entries` and are
 * not narrowed by the date filter — changing the preset refreshes it like
 * everything else, but the money does not move. Only the movement figure beside
 * it is windowed, and the card's caption says which is which so the two are never
 * confused.
 */
export function ReportsScreen() {
  const [filter, setFilter] = useState<ReportDateFilter>({ preset: "month" });

  // The two dates the API is asked for. Derived here (not inside the loader) so
  // they double as the effects dependencies, which is what makes a preset
  // change trigger exactly one reload.
  const period = useMemo(() => {
    const range = resolveDateRange(filter);
    return { from: toApiDate(range.from), to: toApiDate(range.to) };
  }, [filter]);

  const load = useCallback(
    (signal: AbortSignal) =>
      loadReportsSnapshot(
        { dateFrom: period.from, dateTo: period.to },
        { signal },
      ),
    [period.from, period.to],
  );

  const {
    data,
    isLoading,
    isRefreshing,
    error,
    isForbidden,
    refresh,
  } = useApiResource(load, [period.from, period.to]);

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="font-heading text-lg font-semibold">Reports</h1>
        <p className="text-sm text-muted-foreground">
          Read-only snapshots of sales, purchases, expenses and profit.
        </p>
      </header>

      <ReportsDateFilter value={filter} onChange={setFilter} />

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2"
        >
          <p className="text-xs text-destructive">
            {isForbidden ? "You do not have permission to view reports." : error}
          </p>
          <Button type="button" variant="outline" size="sm" onClick={refresh}>
            Try again
          </Button>
        </div>
      )}

      {isLoading && data === null ? (
        <LoadingState />
      ) : data !== null ? (
        <>
          {/* `GET /reports/dashboard` is today-only by construction, so it is
              presented as a reference row rather than as the selected period. */}
          <TodayStrip dashboard={data.dashboard} isRefreshing={isRefreshing} />

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <SalesSummaryCard sales={data.sales} profitLoss={data.profitLoss} />
            <DailySalesCard sales={data.sales} />
            <TopProductsCard sales={data.sales} />
            <TopCategoriesCard sales={data.sales} />
            <TopCustomersCard sales={data.sales} />
            <TopUsersCard sales={data.sales} />
            <ProfitSummaryCard profitLoss={data.profitLoss} />
            <CashRegisterMovementCard report={data.cashRegisterMovements} />
            <CustomerOutstandingCard report={data.customerOutstanding} />
            <SupplierOutstandingCard report={data.supplierOutstanding} />
            <PurchaseSummaryCard purchases={data.purchases} />
            <MonthlySalesCard sales={data.sales} />
            <ExpenseSummaryCard expenses={data.expenses} />
            <LowStockCard inventory={data.inventory} />
          </div>
        </>
      ) : null}
    </div>
  );
}

/** The `GET /reports/dashboard` figures, shown as todays reference row. */
function TodayStrip({
  dashboard,
  isRefreshing,
}: {
  dashboard: DashboardReport;
  isRefreshing: boolean;
}) {
  return (
    <section
      className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5"
      aria-label="Today at a glance"
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-sm font-semibold tracking-tight">
          Today at a glance
        </h2>
        {isRefreshing && (
          <span className="text-xs text-muted-foreground">Refreshing…</span>
        )}
      </div>
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <ReportMetric
          label="Sales today"
          value={formatMoney(dashboard.todayTotalSales)}
          hint={`${dashboard.todaySaleCount} invoices`}
        />
        <ReportMetric
          label="Purchases today"
          value={formatMoney(dashboard.todayPurchaseTotal)}
          hint={`${dashboard.todayPurchaseCount} orders`}
        />
        <ReportMetric
          label="Expenses today"
          value={formatMoney(dashboard.todayTotalExpense)}
          hint={`${dashboard.todayExpenseCount} expenses`}
        />
        <ReportMetric
          label="Cash balance"
          value={formatMoney(dashboard.currentCashBalance)}
          hint="Open register"
        />
      </div>
    </section>
  );
}

/** First-load placeholder, so the grid never renders half-populated cards. */
function LoadingState() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="grid grid-cols-1 gap-4 md:grid-cols-2"
    >
      {[0, 1, 2, 3].map((index) => (
        <div
          key={index}
          className="h-40 animate-pulse rounded-lg border border-border bg-card"
        />
      ))}
      <span className="sr-only">Loading reports…</span>
    </div>
  );
}
