"use client";

import { useCallback } from "react";

import type { LucideIcon } from "lucide-react";
import {
  Boxes,
  PackageX,
  ReceiptText,
  RefreshCw,
  TriangleAlert,
  Truck,
  Wallet,
  WalletCards,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { DashboardKpi } from "@/features/dashboard/components/DashboardKpi";
import { getDashboard } from "@/features/dashboard/services/dashboardService";
import { formatMoney } from "@/features/pos/lib/format";
import { useApiResource } from "@/lib/api/useApiResource";
import { DASHBOARD_UNAVAILABLE_REASONS } from "@/types/dashboard";
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
    <div className="flex flex-col gap-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="min-w-0">
          <h2 className="font-heading text-sm font-semibold tracking-tight">
            Today at a glance
          </h2>
          {/* The day is the server's own, never the browser's, so the label
              cannot drift from the figures when the two timezones differ. */}
          <p className="mt-0.5 text-xs text-muted-foreground">
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

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <RecentSalesPanel />
            <LowStockPanel dashboard={data} />
            <OutstandingPanel />
          </div>
        </>
      ) : null}
    </div>
  );
}


/** The six figures the dashboard endpoint actually returns today. */
function TodayKpis({ dashboard }: { dashboard: DashboardSummary }) {
  return (
    <section
      aria-label="Today"
      className="grid grid-cols-2 gap-2 xl:grid-cols-3"
    >
      <DashboardKpi
        icon={ReceiptText}
        label="Sales today"
        value={formatMoney(dashboard.todayTotalSales)}
        hint={`${dashboard.todaySaleCount} invoices`}
      />
      <DashboardKpi
        icon={ReceiptText}
        label="Net sales today"
        value={formatMoney(dashboard.todayNetSales)}
        tone="muted"
        hint="After discounts and returns"
      />
      <DashboardKpi
        icon={Truck}
        label="Purchases today"
        value={formatMoney(dashboard.todayPurchaseTotal)}
        hint={`${dashboard.todayPurchaseCount} orders`}
      />
      <DashboardKpi
        icon={Wallet}
        label="Expenses today"
        value={formatMoney(dashboard.todayTotalExpense)}
        hint={`${dashboard.todayExpenseCount} recorded`}
      />
      <DashboardKpi
        icon={WalletCards}
        label="Cash in register"
        value={formatMoney(dashboard.currentCashBalance)}
        tone={dashboard.currentCashBalance > 0 ? "success" : "muted"}
        hint={
          dashboard.currentCashBalance > 0 ? "Open register" : "No open register"
        }
      />
      <DashboardKpi
        icon={Boxes}
        label="Low stock"
        value={String(dashboard.lowStockCount)}
        tone={dashboard.lowStockCount > 0 ? "warning" : "muted"}
        hint={
          dashboard.lowStockCount > 0
            ? "At or below threshold"
            : "All above threshold"
        }
      />
    </section>
  );
}

/** The panel a section shows instead of a figure the API did not send. */
function UnavailablePanel({
  title,
  icon: Icon,
  reason,
  hint,
}: {
  title: string;
  icon: LucideIcon;
  reason: string;
  hint: string;
}) {
  return (
    <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
      <header className="flex min-w-0 items-center gap-2">
        <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <h3 className="min-w-0 truncate font-heading text-sm font-semibold tracking-tight">
          {title}
        </h3>
      </header>

      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-8 text-center">
        <TriangleAlert
          className="size-6 text-muted-foreground"
          aria-hidden="true"
        />
        <p className="text-sm font-medium">Not available from the API</p>
        <p className="max-w-md text-xs text-muted-foreground">{reason}</p>
        <p className="max-w-md text-xs text-muted-foreground">{hint}</p>
      </div>
    </section>
  );
}


/**
 * Low stock.
 *
 * `low_stock_count` is a real API figure and is shown as a KPI above, but the
 * endpoint does not say *which* products are low, so the list below states the
 * gap instead of being rebuilt from the paginated `/products` endpoint.
 */
function LowStockPanel({ dashboard }: { dashboard: DashboardSummary }) {
  return (
    <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
      <header className="flex min-w-0 items-center justify-between gap-2">
        <h3 className="min-w-0 truncate font-heading text-sm font-semibold tracking-tight">
          Low stock
        </h3>
        <span className="shrink-0 text-xs text-muted-foreground">
          {dashboard.lowStockCount} product
          {dashboard.lowStockCount === 1 ? "" : "s"}
        </span>
      </header>

      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-8 text-center">
        <PackageX className="size-6 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">Not available from the API</p>
        <p className="max-w-md text-xs text-muted-foreground">
          {DASHBOARD_UNAVAILABLE_REASONS.lowStockItems}
        </p>
        <p className="max-w-md text-xs text-muted-foreground">
          The item list would need the dashboard endpoint to return the products
          behind the count.
        </p>
      </div>
    </section>
  );
}

/**
 * Recent sales.
 *
 * `ReportService::dashboard()` returns aggregate counts and totals only — it has
 * no join or `limit` that could return sale rows. The list is therefore **not**
 * reconstructed from `/sales`: that endpoint is paginated, so its first page
 * would be shown as though it were the whole day.
 */
function RecentSalesPanel() {
  return (
    <UnavailablePanel
      title="Recent sales"
      icon={ReceiptText}
      reason={DASHBOARD_UNAVAILABLE_REASONS.recentSales}
      hint="The list would need the dashboard endpoint to return recent sale rows."
    />
  );
}

/**
 * Outstanding / due.
 *
 * The dashboard endpoint reports no due amount for today. A due total does
 * exist on `GET /reports/sales`, but only for an explicit period, so it is not
 * this screen's today figure and is deliberately not re-derived here.
 */
function OutstandingPanel() {
  return (
    <UnavailablePanel
      title="Outstanding today"
      icon={Wallet}
      reason={DASHBOARD_UNAVAILABLE_REASONS.outstanding}
      hint="A due total would need the dashboard endpoint to report it for today."
    />
  );
}

/** First-load placeholder, so the grid never renders half-populated tiles. */
function LoadingState() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="grid grid-cols-2 gap-2 xl:grid-cols-3"
    >
      {[0, 1, 2, 3, 4, 5].map((index) => (
        <div
          key={index}
          className="h-[58px] animate-pulse rounded-lg border border-border bg-card"
        />
      ))}
      <span className="sr-only">Loading dashboard…</span>
    </div>
  );
}
