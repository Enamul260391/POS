/**
 * `GET /api/v1/reports/dashboard` — the todays summary.
 *
 * Source of truth: `App\Http\Controllers\Api\V1\ReportController@dashboard`
 * and `App\Services\Report\ReportService::dashboard()`.
 *
 * The endpoint is **today-only by construction**. `ReportService::dashboard()`
 * ignores any request filter and hard-codes
 * `['date_from' => $today, 'date_to' => $today]`, where `$today` is
 * `Carbon::now($business->timezone)` — the *business* timezone, not the
 * browser's. The screen therefore sends no date range at all, and the `date`
 * field below is the server's own answer for which day these figures describe.
 * It is displayed verbatim rather than re-derived, so the UI can never claim a
 * different day than the one that was aggregated.
 *
 * Money arrives as **decimal strings** (`Money::format()` returns a
 * two-decimal `string`, never a float), so every monetary field is typed
 * `string` here and converted once in `dashboardMapper`. Counts arrive as
 * integers.
 *
 * There is deliberately no `period` wrapper (the other five report endpoints
 * have one) and **no row collections**: no recent sales, no low-stock product
 * list, no payment breakdown and no outstanding/due total. Anything the screen
 * wants beyond the ten fields below is a documented gap — see
 * {@link DASHBOARD_UNAVAILABLE_REASONS}.
 */

/** `data` of `GET /api/v1/reports/dashboard`, exactly as the API sends it. */
export interface DashboardPayload {
  /** The business-timezone day the figures describe, as `YYYY-MM-DD`. */
  date: string;
  /** Sales invoices recorded today. */
  today_sale_count: number;
  /** Sum of `grand_total` for todays sales. */
  today_total_sales: string;
  /** Net sales for today; the backend currently sends the same `grand_total` sum. */
  today_net_sales: string;
  /** Purchase orders recorded today. */
  today_purchase_count: number;
  /** Sum of `grand_total` for todays purchases. */
  today_purchase_total: string;
  /** Expenses recorded today. */
  today_expense_count: number;
  /** Sum of `amount` for todays expenses. */
  today_total_expense: string;
  /** The open register balance, or `"0.00"` when the user has none open. */
  current_cash_balance: string;
  /** Products at or below their `low_stock_threshold` — a count, not a list. */
  low_stock_count: number;
}

/**
 * The dashboard as the UI renders it: camelCase, and every money string
 * parsed to a `number` exactly once (via `toMoney`) at the mapper boundary.
 *
 * Field-for-field with {@link DashboardPayload}. Nothing is added and nothing
 * is derived — in particular there is no margin, no due/outstanding total and
 * no "vs yesterday" comparison, because the API does not send those.
 */
export interface DashboardSummary {
  /** The business-timezone day the figures describe, as `YYYY-MM-DD`. */
  date: string;
  todaySaleCount: number;
  todayTotalSales: number;
  todayNetSales: number;
  todayPurchaseCount: number;
  todayPurchaseTotal: number;
  todayExpenseCount: number;
  todayTotalExpense: number;
  currentCashBalance: number;
  lowStockCount: number;
}

/* ---------------------------------------------------------------------------
 * Known backend gaps
 * ------------------------------------------------------------------------- */

/**
 * Dashboard panels the API cannot answer, with the reason shown in place of
 * each one.
 *
 * `ReportService::dashboard()` returns a single flat aggregate row: it counts
 * low-stock products but never says *which* ones, and it has no join, `limit`
 * or row collection that could return recent sales. No other route in
 * `routes/api.php` produces these as part of the dashboard, and this phase adds
 * neither an endpoint nor a client-side reconstruction — the panels state the
 * gap instead of showing a number the server never produced.
 *
 * They are intentionally left unavailable rather than approximated from
 * `/sales` or `/products`: those are paginated document lists, so taking the
 * first page would present a partial window as if it were the whole one.
 */
export const DASHBOARD_UNAVAILABLE_REASONS = {
  /** The recent-sales panel. */
  recentSales:
    "The dashboard endpoint returns todays totals only, with no recent sale rows.",
  /** The item list inside the low-stock panel. */
  lowStockItems:
    "The dashboard endpoint returns a low-stock count, but not the products behind it.",
  /** Outstanding / due amounts. */
  outstanding:
    "The dashboard endpoint returns no outstanding or due amount for today.",
} as const;
