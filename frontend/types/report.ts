/**
 * Report API payloads and the domain types the `/reports` screen renders.
 *
 * Source of truth: `App\Http\Controllers\Api\V1\ReportController` and
 * `App\Services\Report\ReportService`. Every one of the six report endpoints
 * answers with an explicit array (aggregate reports have no single resource
 * representation), so there are no `*Resource` classes to mirror and the
 * payload types below are the literal wire shape.
 *
 * Conventions that apply to all six:
 *
 *   - tenancy   the authenticated users `business_id` is the only scope; a
 *               `business_id` is never sent and never returned.
 *   - money     `decimal(15,2)` through `Money::format`, so it arrives as a
 *               **string** (`"1234.50"`), never a float.
 *   - quantity  the ledgers `decimal(15,4)` through `Quantity::format`, so it
 *               arrives as a normalised string (`"5"`, `"2.5"`).
 *   - authority the API is the single source of truth. The frontend never
 *               re-derives a financial aggregate; it only converts wire format
 *               to `number`, in `features/reports/lib/reportMapper.ts`.
 *
 * The two report shapes without a `period` wrapper are the two snapshots:
 * `inventory` is a current-state reading, and `dashboard` is today-only by
 * construction (`ReportService::dashboard()` hard-codes todays window and
 * ignores the requested dates).
 */

/** `data.period` echoed back by the three period-based reports. */
export interface ReportPeriodPayload {
  /** `YYYY-MM-DD`, or `null` when the request sent no lower bound. */
  from: string | null;
  /** `YYYY-MM-DD`, or `null` when the request sent no upper bound. */
  to: string | null;
}

/** The period the API actually applied, read back from the response. */
export interface ReportPeriod {
  /** Inclusive `YYYY-MM-DD` lower bound, or `null`. */
  from: string | null;
  /** Inclusive `YYYY-MM-DD` upper bound, or `null`. */
  to: string | null;
}

/* ---------------------------------------------------------------------------
 * GET /reports/sales
 * ------------------------------------------------------------------------- */

/** `ReportService::sales()` metrics, verbatim. */
export interface SalesReportMetricsPayload {
  sale_count: number;
  total_sales: string;
  subtotal: string;
  discount: string;
  tax: string;
  /** Identical to `total_sales`: `grand_total` is already net of the header
   *  discount and inclusive of tax, so the API never applies them twice. */
  net_sales: string;
  paid_amount: string;
  due_amount: string;
}

/** `data` of `GET /reports/sales`. */
export interface SalesReportPayload {
  period: ReportPeriodPayload;
  metrics: SalesReportMetricsPayload;
}

/**
 * The sales summary as the UI renders it.
 *
 * `grossProfit` / `grossMarginPercent` are typed `null` on purpose: this report
 * carries no cost of goods, and `sale_items` does not preserve `unit_cost`, so
 * a historical gross profit can never be recovered without inventing it. The
 * same limitation is reported explicitly by `GET /reports/profit-loss`.
 */
export interface SalesReport {
  period: ReportPeriod;
  /** `metrics.sale_count`. */
  invoiceCount: number;
  /** `metrics.total_sales` — the invoice grand total, already net of discount. */
  grossSales: number;
  /** `metrics.subtotal`. */
  subtotal: number;
  /** `metrics.discount`. */
  discountTotal: number;
  /** `metrics.tax`. */
  taxTotal: number;
  /** `metrics.net_sales`. */
  netSales: number;
  /** `metrics.paid_amount`. */
  paidAmount: number;
  /** `metrics.due_amount`. */
  dueAmount: number;
  /** Always `null` — see the interface note. Render as a dash. */
  grossProfit: null;
  /** Always `null` — see the interface note. Render as a dash. */
  grossMarginPercent: null;
}

/* ---------------------------------------------------------------------------
 * GET /reports/purchases
 * ------------------------------------------------------------------------- */

/** `ReportService::purchases()` metrics, verbatim. */
export interface PurchaseReportMetricsPayload {
  purchase_count: number;
  purchase_total: string;
  paid_amount: string;
  due_amount: string;
  /** Sum of `purchase_items.quantity` across the matched orders. */
  item_quantity: string;
}

/** `data` of `GET /reports/purchases`. */
export interface PurchaseReportPayload {
  period: ReportPeriodPayload;
  metrics: PurchaseReportMetricsPayload;
}

/** The purchase summary as the UI renders it. */
export interface PurchaseReport {
  period: ReportPeriod;
  /** `metrics.purchase_count`. */
  orderCount: number;
  /** `metrics.purchase_total` — the sum of the orders grand totals. */
  grandTotal: number;
  /** `metrics.paid_amount`. */
  paidAmount: number;
  /** `metrics.due_amount`. */
  dueAmount: number;
  /** `metrics.item_quantity` — units received, on the ledgers 4-digit scale. */
  itemQuantity: number;
}

/* ---------------------------------------------------------------------------
 * GET /reports/expenses
 * ------------------------------------------------------------------------- */

/** One row of `metrics.category_breakdown`. */
export interface ExpenseCategoryBreakdownPayload {
  /** `null` for an expense filed without a category. */
  expense_category_id: number | null;
  /** The category name, or "Uncategorized" when there is no category row. */
  name: string;
  /** `active` / `inactive`, or `null` for the uncategorised bucket. */
  status: string | null;
  expense_count: number;
  total_expense: string;
}

/** `ReportService::expenses()` metrics, verbatim. */
export interface ExpenseReportMetricsPayload {
  expense_count: number;
  total_expense: string;
  category_breakdown: ExpenseCategoryBreakdownPayload[];
}

/** `data` of `GET /reports/expenses`. */
export interface ExpenseReportPayload {
  period: ReportPeriodPayload;
  metrics: ExpenseReportMetricsPayload;
}

/** One category in the expense breakdown. */
export interface ExpenseCategoryBreakdown {
  /** `expense_category_id`, or `null` for the uncategorised bucket. */
  categoryId: number | null;
  /** The category name exactly as the API returned it. */
  category: string;
  /** The same string — it is already the label to render. */
  label: string;
  /** The APIs own `total_expense` for this category. */
  amount: number;
  /** Share of `ExpenseReport.total`, from two API numbers, for display only. */
  percent: number;
  /** The APIs own `expense_count` for this category. */
  expenseCount: number;
}

/** The expense summary as the UI renders it. */
export interface ExpenseReport {
  period: ReportPeriod;
  /** `metrics.expense_count`. */
  count: number;
  /** `metrics.total_expense`. */
  total: number;
  /**
   * Always `null`: the report groups expenses by category only, with no
   * `payment_method` split, so the cash share cannot be read from it. Render as
   * a dash rather than deriving one from any other endpoint.
   */
  cashTotal: null;
  /** `metrics.category_breakdown`, ordered by the API (uncategorised last). */
  byCategory: ExpenseCategoryBreakdown[];
}

/* ---------------------------------------------------------------------------
 * GET /reports/profit-loss
 * ------------------------------------------------------------------------- */

/** `ReportService::profitLoss()` metrics, verbatim. */
export interface ProfitLossMetricsPayload {
  /** Currently always `false`. */
  available: boolean;
  /** Explains why, in the APIs own words; `null` once it becomes available. */
  limitation: string | null;
}

/** `data` of `GET /reports/profit-loss`. */
export interface ProfitLossReportPayload {
  period: ReportPeriodPayload;
  metrics: ProfitLossMetricsPayload;
}

/**
 * The profit/loss answer, which today is always "not available".
 *
 * The endpoint exists precisely so the absence is reported rather than guessed:
 * `sale_items` stores no `unit_cost`, so the cost of the units on a historical
 * invoice line is gone, and gross profit / net profit cannot be produced without
 * either inventing a cost or substituting todays product cost (which would
 * misstate history). The UI therefore renders every cost-derived figure as a
 * dash and shows this `limitation` string verbatim.
 */
export interface ProfitLossReport {
  period: ReportPeriod;
  /** `metrics.available`. */
  available: boolean;
  /** `metrics.limitation`. */
  limitation: string | null;
}

/* ---------------------------------------------------------------------------
 * GET /reports/inventory — a current snapshot, with no `period` wrapper
 * ------------------------------------------------------------------------- */

/** `data` of `GET /reports/inventory`. */
export interface InventoryReportPayload {
  product_count: number;
  total_stock: string;
  low_stock_count: number;
  stock_movement_count: number;
  total_stock_in: string;
  total_stock_out: string;
  /** Provenance of the stock figures, e.g. `"stock_movements"`. */
  source: string;
}

/** The inventory snapshot as the UI renders it. */
export interface InventoryReport {
  /** `product_count` — stock-tracked (non-service) products. */
  productCount: number;
  /** `total_stock` — units on hand across those products. */
  totalStock: number;
  /** `low_stock_count` — products at or below their threshold. */
  lowStockCount: number;
  /** `stock_movement_count` — ledger entries ever recorded. */
  stockMovementCount: number;
  /** `total_stock_in` — units ever received through the ledger. */
  totalStockIn: number;
  /** `total_stock_out` — units ever issued through the ledger. */
  totalStockOut: number;
  /** `source`. */
  source: string;
}

/* ---------------------------------------------------------------------------
 * GET /reports/dashboard — today only, with no `period` wrapper
 * ------------------------------------------------------------------------- */

/** `data` of `GET /reports/dashboard`. */
export interface DashboardReportPayload {
  date: string;
  today_sale_count: number;
  today_total_sales: string;
  today_net_sales: string;
  today_purchase_count: number;
  today_purchase_total: string;
  today_expense_count: number;
  today_total_expense: string;
  /** The open cash registers balance, or `"0.00"` when none is open. */
  current_cash_balance: string;
  low_stock_count: number;
}

/** The dashboard summary as the Reports screen renders it. */
export interface DashboardReport {
  /** `date` — the business-timezone day the figures describe. */
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
 * The whole screen in one object
 * ------------------------------------------------------------------------- */

/** Everything `GET /reports/*` returns for the selected window, mapped once. */
export interface ReportsSnapshot {
  sales: SalesReport;
  purchases: PurchaseReport;
  expenses: ExpenseReport;
  profitLoss: ProfitLossReport;
  inventory: InventoryReport;
  dashboard: DashboardReport;
}

/* ---------------------------------------------------------------------------
 * Known backend gaps
 * ------------------------------------------------------------------------- */

/**
 * The report breakdowns the Reports screen draws but the reports API cannot
 * answer, with the reason shown in place of each one.
 *
 * `App\Services\Report\ReportService` returns one flat aggregate row per report:
 * `sales()` has no `groupBy` on `sale_date`, no join over `payments` and no
 * join over `sale_items`, and `inventory()` counts low-stock products without
 * returning which ones. No other route in `routes/api.php` produces these
 * figures, and this phase adds neither an endpoint nor a client-side
 * reconstruction — so the cards state the gap instead of showing a number the
 * server did not send.
 *
 * They are intentionally left unavailable here rather than approximated from
 * `/sales` or `/products`: those are paginated document lists, so summing them
 * would silently report a partial period as if it were the whole one.
 */
export const REPORT_UNAVAILABLE_REASONS = {
  /** The donut inside the sales summary card. */
  paymentBreakdown:
    "The reports API returns one sales total for the period and no payment-method split.",
  dailySales:
    "The reports API returns one aggregate for the period and no per-day breakdown.",
  monthlySales:
    "The reports API returns one aggregate for the period and no per-month breakdown.",
  topProducts:
    "The reports API returns no product ranking for the period.",
  /** The item list inside the low-stock card. */
  lowStockItems:
    "The inventory report returns a low-stock count, but not the products behind it.",
} as const;
