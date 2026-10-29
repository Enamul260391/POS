"use client";

import { useCallback } from "react";

import {
  Boxes,
  ReceiptText,
  RefreshCw,
  Truck,
  Wallet,
  WalletCards,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { DashboardKpi } from "@/features/dashboard/components/DashboardKpi";
import { getDashboard } from "@/features/dashboard/services/dashboardService";
import { formatMoney } from "@/features/pos/lib/format";
import { useApiResource } from "@/lib/api/useApiResource";
import type { DashboardSummary } from "@/types/dashboard";

/**
 * `/dashboard` — "Today at a glance", fully API-backed.
 *
 * A single screen composed from exactly **one** request:
 * `GET /api/v1/reports/dashboard` (`ReportController@dashboard`). It does not
 * call `/sales`, `/purchases`, `/expenses`, `/products` or `/inventory`, and it
 * sums nothing locally. Every number rendered is a field the server sent.
 *
 * Today-only semantics: the endpoint is today-only *by construction*
 * (`ReportService::dashboard()` hard-codes today's window in the business
 * timezone), so this screen has **no date filter** and does not reuse the
 * `/reports` screen's period selector. The `date` the API returns is shown
 * verbatim next to the heading, so the user always sees which day is on screen.
 *
 * Loading / error / retry come from `useApiResource`, the shared hook every
 * API-backed screen uses: it exposes first-load and refresh states, aborts a
 * superseded run via `AbortController`, and guards against a stale response
 * overwriting newer data. There is no mock fallback — a failure renders an
 * error with a retry, never a placeholder number.
 *
 * Three panels have no backend source and say so explicitly instead of showing
 * a fabricated figure: recent sales, the low-stock product list, and
 * outstanding/due amounts. See `DASHBOARD_UNAVAILABLE_REASONS` in
 * `@/types/dashboard`.
 */
export function DashboardScreen() {
  // Stable loader + an empty dependency list: the Dashboard takes no inputs, so
  // it fetches once per mount (plus once per explicit `refresh()`) and can
  // never re-run because an unrelated value changed identity.
  const load = useCallback((signal: AbortSignal) => getDashboard({ signal }), []);

  const { data, isLoading, isRefreshing, error, isForbidden, refresh } =
    useApiResource(load, []);

  return (
    <div id="dashboard-screen-root" className="flex flex-col gap-8">
      <header id="dashboard-screen-header" className="flex flex-wrap items-baseline justify-between gap-3 rounded-xl bg-gradient-to-r from-violet-500/10 via-indigo-400/10 to-teal-400/10 px-4 py-3">
        <div id="dashboard-screen-title-block" className="min-w-0">
          <h2 className="font-heading text-2xl font-bold tracking-tight text-foreground">
            Today at a glance
          </h2>
          {/* The day is the server's own, never the browser's, so the label
              cannot drift from the figures when the two timezones differ. */}
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {data !== null && data.date !== ""
              ? `Figures for ${data.date}`
              : "Today, in the business time zone"}
          </p>
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={refresh}
          disabled={isRefreshing}
          className="text-sm"
        >
          <RefreshCw
            className={isRefreshing ? "size-4 animate-spin" : "size-4"}
            aria-hidden="true"
          />
          {isRefreshing ? "Refreshing…" : "Refresh"}
        </Button>
      </header>

      {error !== null && (
        <div
          id="dashboard-screen-error"
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2"
        >
          <p className="text-xs text-destructive">
            {isForbidden
              ? "You do not have permission to view the dashboard."
              : error}
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
          <TodayKpis dashboard={data} />
        </>
      ) : null}
    </div>
  );
}


/** The six figures the dashboard endpoint actually returns today. */
function TodayKpis({ dashboard }: { dashboard: DashboardSummary }) {
  return (
    <section
      id="dashboard-today-kpis"
      aria-label="Today"
      className="grid grid-cols-2 gap-8 xl:grid-cols-3"
    >
      <DashboardKpi
        icon={ReceiptText}
        label="Gross Sales Today"
        value={formatMoney(dashboard.todayTotalSales)}
        hint={`${dashboard.todaySaleCount} invoices`}
        tone="success"
      />
      <DashboardKpi
        icon={ReceiptText}
        label="Net Sales Today"
        value={formatMoney(dashboard.todayNetSales)}
        tone="success"
        hint="After returns"
      />
      <DashboardKpi
        id="dashboard-kpi-profit-loss"
        icon={WalletCards}
        label="Profit / Loss Today"
        value={formatMoney(dashboard.todayNetSales - dashboard.todayTotalExpense)}
        hint={dashboard.todayNetSales - dashboard.todayTotalExpense >= 0 ? "Profit" : "Loss"}
        tone={dashboard.todayNetSales - dashboard.todayTotalExpense >= 0 ? "success" : "danger"}
      />
      <DashboardKpi
        icon={Truck}
        label="Purchases Today"
        value={formatMoney(dashboard.todayPurchaseTotal)}
        hint={`${dashboard.todayPurchaseCount} orders`}
        tone="danger"
      />
      <DashboardKpi
        icon={Wallet}
        label="Expenses Today"
        value={formatMoney(dashboard.todayTotalExpense)}
        hint={`${dashboard.todayExpenseCount} recorded`}
        tone="danger"
      />
      <DashboardKpi
        icon={WalletCards}
        label="Cash in Register"
        value={formatMoney(dashboard.currentCashBalance)}
        tone={dashboard.currentCashBalance > 0 ? "success" : "warning"}
        hint={
          dashboard.currentCashBalance > 0 ? "Open register" : "No open register"
        }
      
      />
      <DashboardKpi
        icon={Boxes}
        label="Low Stock"
        value={String(dashboard.lowStockCount)}
        tone={dashboard.lowStockCount > 0 ? "warning" : "success"}
        hint={
          dashboard.lowStockCount > 0
            ? "At or below threshold"
            : "All above threshold"
        }
      />
    </section>
  );
}
/** First-load placeholder, so the grid never renders half-populated tiles. */
function LoadingState() {
  return (
    <div
      id="dashboard-loading-state"
      role="status"
      aria-live="polite"
      className="grid grid-cols-2 gap-8 xl:grid-cols-3"
    >
      {[0, 1, 2, 3, 4, 5].map((index) => (
        <div
          key={index}
          className="flex items-center gap-8 rounded-xl border border-border bg-gradient-to-br from-white to-slate-50 px-4 py-5 shadow-sm dark:from-slate-800 dark:to-slate-900 h-[128px]"
        >
          <div className="size-11 shrink-0 animate-pulse rounded-xl bg-muted" />
          <div className="grid min-w-0 flex-1 gap-2">
            <div className="h-4 w-24 animate-pulse rounded bg-muted" />
            <div className="h-6 w-32 animate-pulse rounded bg-muted" />
          </div>
        </div>
      ))}
      <span className="sr-only">Loading dashboard…</span>
    </div>
  );
}
