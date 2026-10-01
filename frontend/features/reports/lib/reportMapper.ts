import { toMoney, toQuantity, toText } from "@/lib/api/values";
import type {
  DashboardReport,
  DashboardReportPayload,
  ExpenseCategoryBreakdown,
  ExpenseCategoryBreakdownPayload,
  ExpenseReport,
  ExpenseReportPayload,
  InventoryReport,
  InventoryReportPayload,
  ProfitLossReport,
  ProfitLossReportPayload,
  PurchaseReport,
  PurchaseReportPayload,
  ReportPeriod,
  ReportPeriodPayload,
  SalesReport,
  SalesReportPayload,
} from "@/types/report";

/**
 * `GET /reports/*` payloads → the domain types the `/reports` screen renders.
 *
 * This module owns every snake_case → camelCase conversion for reporting, plus
 * the two wire dialects the reports use:
 *
 *   - money     arrives as a decimal **string** (`"25000.00"`), because
 *               `ReportService` pipes every figure through `Money::format`.
 *               It is read with the shared `toMoney`.
 *   - quantity  arrives as a normalised string (`"5"`, `"2.5"`), because the
 *               ledger is `decimal(15,4)` and `ReportService` pipes it through
 *               `Quantity::format`. It is read with the shared `toQuantity`.
 *
 * What this file deliberately does **not** do is compute a financial aggregate.
 * Every monetary and count figure below is read straight off the payload; the
 * only derived value in the whole module is `ExpenseCategoryBreakdown.percent`,
 * which is the share of the API's *own* total and exists purely to render a bar
 * next to the amount it belongs to. Nothing is summed, averaged or costed here,
 * and a figure the API did not send is surfaced as `null` rather than filled in
 * from another endpoint.
 */

/** The `period` block, normalised. The API echoes `null` for an open bound. */
function toPeriod(payload: ReportPeriodPayload | undefined): ReportPeriod {
  return {
    from: toText(payload?.from),
    to: toText(payload?.to),
  };
}

/**
 * Share of a total, to two decimals — `0` when the total is `0` so a period
 * with no spend cannot divide by zero.
 */
function sharePercent(amount: number, total: number): number {
  return total > 0 ? Math.round((amount / total) * 10000) / 100 : 0;
}

/** `GET /reports/sales` → {@link SalesReport}. */
export function toSalesReport(payload: SalesReportPayload): SalesReport {
  return {
    period: toPeriod(payload?.period),
    invoiceCount: Number(payload?.metrics?.sale_count ?? 0),
    grossSales: toMoney(payload?.metrics?.total_sales),
    subtotal: toMoney(payload?.metrics?.subtotal),
    discountTotal: toMoney(payload?.metrics?.discount),
    taxTotal: toMoney(payload?.metrics?.tax),
    netSales: toMoney(payload?.metrics?.net_sales),
    paidAmount: toMoney(payload?.metrics?.paid_amount),
    dueAmount: toMoney(payload?.metrics?.due_amount),
    // Not derived: this report carries no cost of goods, and `sale_items` has
    // no `unit_cost`, so a historical gross profit is unknowable. The literal
    // `null` (rather than a placeholder number) is what keeps the UI honest.
    grossProfit: null,
    grossMarginPercent: null,
  };
}

/** `GET /reports/purchases` → {@link PurchaseReport}. */
export function toPurchaseReport(payload: PurchaseReportPayload): PurchaseReport {
  return {
    period: toPeriod(payload?.period),
    orderCount: Number(payload?.metrics?.purchase_count ?? 0),
    grandTotal: toMoney(payload?.metrics?.purchase_total),
    paidAmount: toMoney(payload?.metrics?.paid_amount),
    dueAmount: toMoney(payload?.metrics?.due_amount),
    itemQuantity: toQuantity(payload?.metrics?.item_quantity),
  };
}


/** One row of `metrics.category_breakdown` → {@link ExpenseCategoryBreakdown}. */
function toExpenseCategoryBreakdown(
  payload: ExpenseCategoryBreakdownPayload,
  total: number,
): ExpenseCategoryBreakdown {
  // The API already substitutes "Uncategorized" for a missing category row, so
  // the name is used verbatim; the fallback only guards a malformed payload.
  const name = toText(payload?.name) ?? "Uncategorized";
  const amount = toMoney(payload?.total_expense);

  return {
    categoryId: payload?.expense_category_id ?? null,
    category: name,
    label: name,
    amount,
    percent: sharePercent(amount, total),
    expenseCount: Number(payload?.expense_count ?? 0),
  };
}

/** `GET /reports/expenses` → {@link ExpenseReport}. */
export function toExpenseReport(payload: ExpenseReportPayload): ExpenseReport {
  const total = toMoney(payload?.metrics?.total_expense);
  const breakdown = Array.isArray(payload?.metrics?.category_breakdown)
    ? payload.metrics.category_breakdown
    : [];

  return {
    period: toPeriod(payload?.period),
    count: Number(payload?.metrics?.expense_count ?? 0),
    total,
    // The report groups by category only — there is no `payment_method`
    // aggregate to read, so the cash share stays unavailable rather than being
    // approximated from the expenses list endpoint.
    cashTotal: null,
    byCategory: breakdown.map((row) => toExpenseCategoryBreakdown(row, total)),
  };
}

/** `GET /reports/profit-loss` → {@link ProfitLossReport}. */
export function toProfitLossReport(
  payload: ProfitLossReportPayload,
): ProfitLossReport {
  return {
    period: toPeriod(payload?.period),
    available: payload?.metrics?.available === true,
    limitation: toText(payload?.metrics?.limitation),
  };
}

/** `GET /reports/inventory` → {@link InventoryReport}. */
export function toInventoryReport(payload: InventoryReportPayload): InventoryReport {
  return {
    productCount: Number(payload?.product_count ?? 0),
    totalStock: toQuantity(payload?.total_stock),
    lowStockCount: Number(payload?.low_stock_count ?? 0),
    stockMovementCount: Number(payload?.stock_movement_count ?? 0),
    totalStockIn: toQuantity(payload?.total_stock_in),
    totalStockOut: toQuantity(payload?.total_stock_out),
    source: toText(payload?.source) ?? "",
  };
}

/** `GET /reports/dashboard` → {@link DashboardReport}. */
export function toDashboardReport(
  payload: DashboardReportPayload,
): DashboardReport {
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
