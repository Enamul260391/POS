import { toMoney, toText } from "@/lib/api/values";

import type { DashboardPayload, DashboardSummary } from "@/types/dashboard";

/**
 * Wire-format mapping for `GET /reports/dashboard`.
 *
 * This is a **shape** mapper and nothing else. It converts Laravel's
 * snake_case keys to the frontend's camelCase and parses the decimal money
 * strings into numbers, then stops. It deliberately does not:
 *
 *   - add a field the API did not send (no margin, no due/outstanding total,
 *     no "vs yesterday" delta),
 *   - subtract or re-apply discount, tax or refunds to a total the server has
 *     already netted, or
 *   - combine two fields into a third.
 *
 * Every read is defensive (`?.` + a `?? 0` / `?? ""` fallback) so a partially
 * populated payload can never throw mid-render: the same convention
 * `reportMapper` uses for the other five report endpoints.
 */
export function toDashboardSummary(payload: DashboardPayload): DashboardSummary {
  return {
    date: toText(payload?.date) ?? "",
    todaySaleCount: Number(payload?.today_sale_count ?? 0),
    todayTotalSales: toMoney(payload?.today_total_sales),
    todayNetSales: toMoney(payload?.today_net_sales),
    todayPurchaseCount: Number(payload?.today_purchase_count ?? 0),
    todayPurchaseTotal: toMoney(payload?.today_purchase_total),
    todayExpenseCount: Number(payload?.today_expense_count ?? 0),
    todayTotalExpense: toMoney(payload?.today_total_expense),
    currentCashBalance: toMoney(payload?.current_cash_balance),
    lowStockCount: Number(payload?.low_stock_count ?? 0),
  };
}
