import { toMoney, toQuantity, toText } from "@/lib/api/values";
import type {
  CashMovementDirection,
  CashMovementOrigin,
  CashMovementRow,
  CashMovementRowPayload,
  CashMovementTypeBreakdown,
  CashMovementTypeBreakdownPayload,
  CashRegisterMovementReport,
  CashRegisterMovementReportPayload,
  CustomerOutstandingReport,
  CustomerOutstandingReportPayload,
  CustomerOutstandingRow,
  CustomerOutstandingRowPayload,
  DailySalesPoint,
  DailySalesPointPayload,
  DashboardReport,
  DashboardReportPayload,
  ExpenseCategoryBreakdown,
  ExpenseCategoryBreakdownPayload,
  ExpenseReport,
  ExpenseReportPayload,
  InventoryReport,
  InventoryReportPayload,
  MonthlySalesPoint,
  MonthlySalesPointPayload,
  ProfitLossReport,
  ProfitLossReportPayload,
  PurchaseReport,
  PurchaseReportPayload,
  ReportPeriod,
  ReportPeriodPayload,
  SalesPaymentBreakdown,
  SalesPaymentBreakdownPayload,
  SalesRefundBreakdown,
  SalesRefundBreakdownPayload,
  SalesReport,
  SalesReportPayload,
  SupplierOutstandingReport,
  SupplierOutstandingReportPayload,
  SupplierOutstandingRow,
  SupplierOutstandingRowPayload,
  TopCategory,
  TopCategoryPayload,
  TopCustomer,
  TopCustomerPayload,
  TopProduct,
  TopProductPayload,
  TopUser,
  TopUserPayload,
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
 * only derived values in the whole module are `ExpenseCategoryBreakdown.percent`,
 * which is the share of the API's *own* total and exists purely to render a bar
 * next to the amount it belongs to, and the bar heights in the daily and monthly
 * sales trends, which are each point scaled against the largest point the API
 * itself sent. Nothing is summed, averaged or costed here, and a figure the API
 * did not send is surfaced as `null` rather than filled in from another endpoint.
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

/** One row of `metrics.payment_breakdown` → {@link SalesPaymentBreakdown}. */
function toSalesPaymentBreakdown(
  payload: SalesPaymentBreakdownPayload,
  total: number,
): SalesPaymentBreakdown {
  // The API returns the stored `payment_method` verbatim (`cash`, `card`,
  // `bank`, `cheque`, `due`). It is used as the label too rather than being
  // translated into the POS's richer display vocabulary: the report describes
  // what the ledger recorded, and a bKash settlement really is stored as `bank`.
  const method = toText(payload?.payment_method) ?? "unknown";

  return {
    method,
    label: method,
    amount: toMoney(payload?.total_amount),
    percent: sharePercent(toMoney(payload?.total_amount), total),
    paymentCount: Number(payload?.payment_count ?? 0),
  };
}

/** One row of `metrics.refund_breakdown` → {@link SalesRefundBreakdown}. */
function toSalesRefundBreakdown(
  payload: SalesRefundBreakdownPayload,
  total: number,
): SalesRefundBreakdown {
  // Same rule as the inbound split: the stored method is the label. A refund
  // method is never `due`, because nothing was ever collected through it.
  const method = toText(payload?.payment_method) ?? "unknown";

  return {
    method,
    label: method,
    amount: toMoney(payload?.refund_amount),
    percent: sharePercent(toMoney(payload?.refund_amount), total),
    refundCount: Number(payload?.refund_count ?? 0),
  };
}

/** One point of `metrics.daily_series` → {@link DailySalesPoint}. */
function toDailySalesPoint(payload: DailySalesPointPayload): DailySalesPoint {
  // The `date` is the API's own `sale_date` as a bare `YYYY-MM-DD` calendar day
  // and is kept verbatim: reformatting it through `new Date()` would put it
  // through UTC parsing and could shift the day west of Greenwich.
  //
  // `toMoney` is the same read every other money figure uses, so a `"0.00"` day
  // with no sales becomes `0` and renders as a flat bar rather than a gap —
  // which is exactly what the API stated, not a figure invented here.
  return {
    date: toText(payload?.date) ?? "",
    totalSales: toMoney(payload?.total_sales),
  };
}

/** One point of `metrics.monthly_series` → {@link MonthlySalesPoint}. */
function toMonthlySalesPoint(payload: MonthlySalesPointPayload): MonthlySalesPoint {
  // The `month` is a `YYYY-MM` **calendar identifier**, kept verbatim for the
  // same reason the daily `date` is: parsing it as a timestamp would resolve it
  // to UTC and could label January as December for a viewer west of Greenwich.
  //
  // `toMoney` reads the figure exactly as the daily series does, so a `"0.00"`
  // month with no sales becomes `0` and renders as a flat bar rather than a gap.
  return {
    month: toText(payload?.month) ?? "",
    totalSales: toMoney(payload?.total_sales),
  };
}

/**
 * One row of `metrics.top_products` → {@link TopProduct}.
 *
 * Follows the same three rules as the two series mappers beside it:
 *
 *  - **Text through `toText`,** so a blank or missing `product_name` becomes an
 *    empty string rather than leaking `undefined` into the DOM. The API only
 *    ever sends a row for a product it actually joined, so a name is always
 *    present; the guard is there so a malformed row cannot blank the card.
 *  - **Money through `toMoney` and quantity through `toQuantity`,** because the
 *    API sends both as decimal strings — `total_sales` from `Money::format` and
 *    `quantity` from `Quantity::format`. `toQuantity` is the correct read for the
 *    second: the ledger is `decimal(15,4)`, so a weighted item keeps its
 *    fraction where `toMoney` would round it to two decimals.
 *  - **`product_id` preserved exactly.** It is the row's React key and the
 *    server's own tie-break; rounding or stringifying it would break both.
 *
 * There is deliberately **no `Date` parsing and no sorting** in here. A product
 * has no date, and the order is the API's — re-ranking it in the browser would
 * have the client deciding a ranking the server owns, and two products with
 * equal revenue would then be ordered by whatever JavaScript felt like.
 */
function toTopProduct(payload: TopProductPayload): TopProduct {
  return {
    productId: Number(payload?.product_id),
    productName: toText(payload?.product_name) ?? "",
    quantity: toQuantity(payload?.quantity),
    totalSales: toMoney(payload?.total_sales),
  };
}

/**
 * One row of `metrics.top_categories` → {@link TopCategory}.
 *
 * Deliberately identical in shape to {@link toTopProduct}, and for the same
 * reasons — a category row is the same reading of the same payload, one join
 * further down:
 *
 *  - **Text through `toText`,** so a blank or missing `category_name` becomes an
 *    empty string rather than leaking `undefined` into the DOM. The API only ever
 *    sends a row for a category it actually joined, so a name is always present.
 *  - **Money through `toMoney` and quantity through `toQuantity`,** because the
 *    API sends both as decimal strings — `total_sales` from `Money::format` and
 *    `quantity` from `Quantity::format`. `toQuantity` is the correct read for the
 *    second: the ledger is `decimal(15,4)`, so a category sold by weight keeps its
 *    fraction where `toMoney` would round it to two decimals.
 *  - **`category_id` preserved exactly.** It is the row's React key and the
 *    server's own tie-break; rounding or stringifying it would break both.
 *
 * There is deliberately **no `Date` parsing, no sorting and no top-N trimming**
 * in here. A category has no date; the order is the API's, and re-ranking it in
 * the browser would have the client deciding a ranking the server owns.
 */
function toTopCategory(payload: TopCategoryPayload): TopCategory {
  return {
    categoryId: Number(payload?.category_id),
    categoryName: toText(payload?.category_name) ?? "",
    quantity: toQuantity(payload?.quantity),
    totalSales: toMoney(payload?.total_sales),
  };
}

/**
 * One row of `metrics.top_customers` → {@link TopCustomer}.
 *
 * Deliberately identical in shape to {@link toTopProduct} and
 * {@link toTopCategory}, and for the same reasons — a customer row is the same
 * reading of the same payload, joined one hop sideways instead of down:
 *
 *  - **Text through `toText`,** so a blank or missing `customer_name` becomes
 *    an empty string rather than leaking `undefined` into the DOM. The API only
 *    ever sends a row for a customer it actually joined, so a name is always
 *    present; the guard is there so a malformed row cannot blank the card.
 *  - **Money through `toMoney`,** because the API sends `total_sales` as a
 *    decimal string from `Money::format` — a float would round a revenue figure
 *    on the way to the screen.
 *  - **`customer_id` preserved exactly.** It is the row's React key and the
 *    server's own tie-break; rounding or stringifying it would break both.
 *  - **`invoice_count` read as the count it is.** `Number(...)` on an integer,
 *    with no derivation from lines or payments — the API already counted
 *    invoices, and recomputing that here would be the client second-guessing a
 *    figure it cannot see the inputs for.
 *
 * There is deliberately **no sorting, no trimming and no summing** here, and in
 * particular **no walk-in row**. A sale with no customer is counted in
 * `total_sales` and cannot appear here, so this list deliberately does not
 * reconcile with that total; inventing a placeholder to close the gap would put
 * a customer on screen that the server never sent.
 */
function toTopCustomer(payload: TopCustomerPayload): TopCustomer {
  return {
    customerId: Number(payload?.customer_id),
    customerName: toText(payload?.customer_name) ?? "",
    invoiceCount: Number(payload?.invoice_count ?? 0),
    totalSales: toMoney(payload?.total_sales),
  };
}

/**
 * One row of `metrics.top_users` → {@link TopUser}.
 *
 * Deliberately identical in shape to {@link toTopProduct}, {@link toTopCategory}
 * and {@link toTopCustomer}, and for the same reasons — a staff row is the same
 * reading of the same payload, joined one hop sideways instead of down:
 *
 *  - **Text through `toText`,** so a blank or missing `user_name` becomes an
 *    empty string rather than leaking `undefined` into the DOM. The API only
 *    ever sends a row for a user it actually joined inside the caller's own
 *    business, so a name is always present; the guard is there so a malformed
 *    row cannot blank the card.
 *  - **Money through `toMoney`,** because the API sends `total_sales` as a
 *    decimal string from `Money::format` — a float would round a revenue figure
 *    on the way to the screen.
 *  - **`user_id` preserved exactly.** It is the row's React key and the
 *    server's own tie-break; rounding or stringifying it would break both.
 *  - **`invoice_count` read as the count it is.** `Number(...)` on an integer,
 *    with no derivation from lines or payments — the API already counted
 *    invoices, and recomputing that here would be the client second-guessing a
 *    figure it was given.
 *
 * **No placeholder row is appended** for a sale with no cashier. That money is
 * still inside `grossSales`, so this list does not reconcile with it — and it
 * must not be made to. A fabricated staff member in a leaderboard would be a
 * person the server never sent.
 */
function toTopUser(payload: TopUserPayload): TopUser {
  return {
    userId: Number(payload?.user_id),
    userName: toText(payload?.user_name) ?? "",
    invoiceCount: Number(payload?.invoice_count ?? 0),
    totalSales: toMoney(payload?.total_sales),
  };
}

/** `GET /reports/sales` → {@link SalesReport}. */
export function toSalesReport(payload: SalesReportPayload): SalesReport {
  const breakdown = Array.isArray(payload?.metrics?.payment_breakdown)
    ? payload.metrics.payment_breakdown
    : [];
  const refundBreakdown = Array.isArray(payload?.metrics?.refund_breakdown)
    ? payload.metrics.refund_breakdown
    : [];
  const dailySeries = Array.isArray(payload?.metrics?.daily_series)
    ? payload.metrics.daily_series
    : [];
  const monthlySeries = Array.isArray(payload?.metrics?.monthly_series)
    ? payload.metrics.monthly_series
    : [];
  const topProducts = Array.isArray(payload?.metrics?.top_products)
    ? payload.metrics.top_products
    : [];
  const topCategories = Array.isArray(payload?.metrics?.top_categories)
    ? payload.metrics.top_categories
    : [];
  const topCustomers = Array.isArray(payload?.metrics?.top_customers)
    ? payload.metrics.top_customers
    : [];
  const topUsers = Array.isArray(payload?.metrics?.top_users)
    ? payload.metrics.top_users
    : [];

  // The percent is a share of the breakdown's *own* total — the money actually
  // recorded against the invoices in the window — not of `paid_amount`. The two
  // can differ (an invoice's paid column and its payment rows are written by
  // different code paths), so mixing them would produce a bar that disagrees
  // with the API's own figures.
  const breakdownTotal = breakdown.reduce(
    (sum, row) => sum + toMoney(row?.total_amount),
    0,
  );
  const refundTotal = refundBreakdown.reduce(
    (sum, row) => sum + toMoney(row?.refund_amount),
    0,
  );

  return {
    period: toPeriod(payload?.period),
    invoiceCount: Number(payload?.metrics?.sale_count ?? 0),
    grossSales: toMoney(payload?.metrics?.total_sales),
    subtotal: toMoney(payload?.metrics?.subtotal),
    discountTotal: toMoney(payload?.metrics?.discount),
    taxTotal: toMoney(payload?.metrics?.tax),
    returnsCount: Number(payload?.metrics?.returns_count ?? 0),
    returnsAmount: toMoney(payload?.metrics?.returns_amount),
    netSales: toMoney(payload?.metrics?.net_sales),
    paidAmount: toMoney(payload?.metrics?.paid_amount),
    dueAmount: toMoney(payload?.metrics?.due_amount),
    customerOutstanding: toMoney(payload?.metrics?.customer_outstanding),
    // Not derived here: this report carries no cost of goods, so gross profit and
    // margin belong to `GET /reports/profit-loss`, which owns `unit_cost`.
    byPaymentMethod: breakdown.map((row) =>
      toSalesPaymentBreakdown(row, breakdownTotal),
    ),
    refundCount: Number(payload?.metrics?.refund_count ?? 0),
    refundAmount: toMoney(payload?.metrics?.refund_amount),
    byRefundMethod: refundBreakdown.map((row) =>
      toSalesRefundBreakdown(row, refundTotal),
    ),
    netCollectedAmount: toMoney(payload?.metrics?.net_collected_amount),
    // Read, not built: the API grouped the axis, filled the quiet days with
    // zeros and put it in calendar order. Nothing is summed or interpolated
    // here, so a chart drawn from this cannot disagree with `grossSales`.
    dailySales: dailySeries.map(toDailySalesPoint),
    // Read the same way and on the same terms: the API grouped by calendar month,
    // filled the quiet months with zeros and ordered the axis. No month is
    // bucketed, invented or summed here.
    monthlySales: monthlySeries.map(toMonthlySalesPoint),
    // Read, not rebuilt: the API grouped by product, ordered by revenue with a
    // deterministic tie-break and cut to its own top N. Nothing is summed,
    // re-sorted or trimmed here, so this list is exactly the leaderboard the
    // server published. An empty array means no product lines in the window,
    // which the card renders as an explicit empty state.
    topProducts: topProducts.map(toTopProduct),
    // Read on the same terms: the API grouped the sale lines by the category
    // their product belongs to, ordered by revenue with a deterministic
    // tie-break and cut to its own top N. Nothing is summed, re-sorted or
    // trimmed here. An empty array means no line in the window could name a
    // category, which the card renders as an explicit empty state — never as a
    // placeholder row.
    topCategories: topCategories.map(toTopCategory),
    // Read on the same terms one last time: the API grouped the invoices by the
    // customer each one names, ordered by gross revenue with a deterministic
    // tie-break and cut to its own top N. Nothing is summed, re-sorted or
    // trimmed here, and **no walk-in row is appended** — the list is empty when
    // no sale could be attributed to a customer, which the card states as an
    // explicit empty state rather than dressing up as a customer.
    topCustomers: topCustomers.map(toTopCustomer),
    // Read on the same terms once more: the API grouped the invoices by the staff
    // member recorded on the sale itself (`sales.cashier_id`), ordered by gross
    // revenue with a deterministic tie-break and cut to its own top N. Nothing is
    // summed, re-sorted or trimmed here, and **no placeholder row is appended**
    // for an unassigned sale — the list is empty when no sale could be attributed
    // to a staff member, which the card states as an explicit empty state rather
    // than dressing up as a person.
    topUsers: topUsers.map(toTopUser),
  };
}

/**
 * `GET /reports/customer-outstanding` → {@link CustomerOutstandingReport}.
 *
 * Nothing here is computed. `receivable`, `customer_credit` and
 * `net_outstanding` are read off the payload exactly as the API published them —
 * in particular the summary is **not** re-derived by summing the rows, because the
 * API aggregates it in SQL over the same grouped builder and a client-side re-sum
 * would be a second implementation that could drift from the server's.
 *
 * The sign on each balance is preserved rather than made absolute, so a negative
 * row still reads as "the business owes this customer" in the table.
 */
export function toCustomerOutstandingReport(
  payload: CustomerOutstandingReportPayload,
): CustomerOutstandingReport {
  const rows = Array.isArray(payload?.metrics?.customers)
    ? payload.metrics.customers
    : [];

  return {
    period: toPeriod(payload?.period),
    customerCount: Number(payload?.metrics?.customer_count ?? 0),
    receivable: toMoney(payload?.metrics?.receivable),
    customerCredit: toMoney(payload?.metrics?.customer_credit),
    netOutstanding: toMoney(payload?.metrics?.net_outstanding),
    periodMovement: toMoney(payload?.metrics?.period_movement),
    // Read, not re-sorted and not trimmed: the API grouped by customer, ordered
    // receivables before credits with a deterministic id tie-break, and dropped the
    // settled ones with `HAVING balance != 0`. The order is the one the server chose
    // and the list is the whole register — a receivables statement, not a top-N.
    customers: rows.map(toCustomerOutstandingRow),
  };
}

/** One row of `metrics.customers` → {@link CustomerOutstandingRow}. */
function toCustomerOutstandingRow(
  payload: CustomerOutstandingRowPayload,
): CustomerOutstandingRow {
  return {
    customerId: payload?.customer_id ?? 0,
    customerName: toText(payload?.customer_name) ?? "",
    // Signed, deliberately. `toMoney` parses the decimal string and keeps the
    // minus sign, so the row cannot contradict the ledger it came from.
    balance: toMoney(payload?.balance),
    // The API names the side rather than leaving it to be inferred from the sign.
    // An unrecognised value falls back to `"receivable"` because that is the
    // reading a positive balance supports; a credit mislabelled as a debt would
    // overstate what the business is owed.
    status: payload?.status === "customer_credit" ? "customer_credit" : "receivable",
    periodMovement: toMoney(payload?.period_movement),
  };
}

/**
 * `GET /reports/supplier-outstanding` → {@link SupplierOutstandingReport}.
 *
 * Nothing here is computed. `payable`, `supplier_credit` and `net_outstanding`
 * are read off the payload exactly as the API published them — in particular the
 * summary is **not** re-derived by summing the rows, because the API aggregates
 * it in SQL over the same grouped builder and a client-side re-sum would be a
 * second implementation that could drift from the server's.
 *
 * The sign on each balance is preserved rather than made absolute, so a negative
 * row still reads as "the supplier owes the business" in the table.
 */
export function toSupplierOutstandingReport(
  payload: SupplierOutstandingReportPayload,
): SupplierOutstandingReport {
  const rows = Array.isArray(payload?.metrics?.suppliers)
    ? payload.metrics.suppliers
    : [];

  return {
    period: toPeriod(payload?.period),
    supplierCount: Number(payload?.metrics?.supplier_count ?? 0),
    payable: toMoney(payload?.metrics?.payable),
    supplierCredit: toMoney(payload?.metrics?.supplier_credit),
    netOutstanding: toMoney(payload?.metrics?.net_outstanding),
    periodMovement: toMoney(payload?.metrics?.period_movement),
    // Read, not re-sorted and not trimmed: the API grouped by supplier, ordered
    // payables before credits with a deterministic id tie-break, and dropped the
    // settled ones with `HAVING balance != 0`. The order is the one the server
    // chose and the list is the whole register — a payables statement, not a top-N.
    suppliers: rows.map(toSupplierOutstandingRow),
  };
}

/** One row of `metrics.suppliers` → {@link SupplierOutstandingRow}. */
function toSupplierOutstandingRow(
  payload: SupplierOutstandingRowPayload,
): SupplierOutstandingRow {
  return {
    supplierId: payload?.supplier_id ?? 0,
    supplierName: toText(payload?.supplier_name) ?? "",
    // Signed, deliberately. `toMoney` parses the decimal string and keeps the
    // minus sign, so the row cannot contradict the figure it came from.
    balance: toMoney(payload?.balance),
    // The API names the side rather than leaving it to be inferred from the sign.
    // An unrecognised value falls back to `"payable"` because that is the reading
    // a positive balance supports; a credit mislabelled as a debt would overstate
    // what the business owes its suppliers.
    status: payload?.status === "supplier_credit" ? "supplier_credit" : "payable",
    periodMovement: toMoney(payload?.period_movement),
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

/**
 * Reads a figure the API withholds.
 *
 * A withheld cost-derived metric arrives as JSON `null`. It must stay `null`
 * rather than becoming `0`: `toMoney(null)` is `0`, which would render a
 * perfectly respectable looking "cost of goods ৳0.00" and a profit equal to
 * the full revenue — the exact overstatement the API withheld to prevent.
 */
function toNullableMoney(
  value: string | number | null | undefined,
): number | null {
  return value === null || value === undefined ? null : toMoney(value);
}

/** `GET /reports/profit-loss` → {@link ProfitLossReport}. */
export function toProfitLossReport(
  payload: ProfitLossReportPayload,
): ProfitLossReport {
  return {
    period: toPeriod(payload?.period),
    available: payload?.metrics?.available === true,
    limitation: toText(payload?.metrics?.limitation),
    revenue: toMoney(payload?.metrics?.revenue),
    discount: toMoney(payload?.metrics?.discount),
    tax: toMoney(payload?.metrics?.tax),
    returnsCount: Number(payload?.metrics?.returns_count ?? 0),
    returnsAmount: toMoney(payload?.metrics?.returns_amount),
    netRevenue: toMoney(payload?.metrics?.net_revenue),
    costOfGoods: toNullableMoney(payload?.metrics?.cost_of_goods),
    returnedCostOfGoods: toNullableMoney(payload?.metrics?.returned_cost_of_goods),
    netCostOfGoods: toNullableMoney(payload?.metrics?.net_cost_of_goods),
    grossProfit: toNullableMoney(payload?.metrics?.gross_profit),
    netGrossProfit: toNullableMoney(payload?.metrics?.net_gross_profit),
    grossMarginPercent:
      payload?.metrics?.gross_margin_percent === null ||
      payload?.metrics?.gross_margin_percent === undefined
        ? null
        : toMoney(payload.metrics.gross_margin_percent),
    totalExpense: toMoney(payload?.metrics?.total_expense),
    netProfit: toNullableMoney(payload?.metrics?.net_profit),
    unpricedLines: Number(payload?.metrics?.unpriced_lines ?? 0),
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

/**
 * Renders an ISO timestamp as a short local date + time.
 *
 * Deliberately **not** `toISOString()`-derived: the API sends a UTC instant and the
 * drawer timeline is something a cashier reads in their own clock, so the local
 * calendar getters are used. An unparseable value yields `""` rather than
 * `"Invalid Date"`, so a malformed row degrades to a blank cell instead of putting
 * noise on the card.
 */
function toLocalTimestamp(value: string | null | undefined): string {
  const text = toText(value);
  if (text === null) {
    return "";
  }

  const parsed = new Date(text);
  if (Number.isNaN(parsed.getTime())) {
    return "";
  }

  return parsed.toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * The short name of a referenced document class, e.g. `"Payment"` for
 * `"App\\Models\\Payment"`.
 *
 * The API echoes `reference_type` verbatim because that is exactly what the column
 * holds, and no column anywhere stores a human label for the referenced document.
 * Splitting the class name is the only honest thing to render here: it invents no
 * words, it just stops the UI printing a PHP namespace at the user.
 */
function toReferenceLabel(value: string | null | undefined): string | null {
  const text = toText(value);
  if (text === null) {
    return null;
  }

  const parts = text.split("\\");

  return parts[parts.length - 1] || text;
}

/**
 * Reads the API's direction, defaulting an absent value to `non_movement`.
 *
 * The safe default is the one that cannot corrupt a total: an unknown direction is
 * treated as a marker and therefore excluded from the cash figures, rather than
 * being guessed into `in` and inflating a cash-in the server never reported.
 */
function toCashDirection(value: unknown): CashMovementDirection {
  return value === "in" || value === "out" ? value : "non_movement";
}

/** Reads the API's origin, defaulting an absent value to `non_movement`. */
function toCashOrigin(value: unknown): CashMovementOrigin {
  return value === "manual" || value === "automatic" ? value : "non_movement";
}

/** One row of `metrics.by_type` → {@link CashMovementTypeBreakdown}. */
function toCashMovementBreakdown(
  payload: CashMovementTypeBreakdownPayload,
): CashMovementTypeBreakdown {
  return {
    // The stored value, verbatim: the column is a plain string rather than an enum,
    // so the API publishes what is actually there and the UI must not second-guess
    // it by mapping it onto a closed list of known types.
    type: String(payload?.type ?? ""),
    // The label the server sent, used as-is rather than re-invented here, so the
    // wording lives with the report that owns it.
    label: toText(payload?.label) ?? String(payload?.type ?? ""),
    direction: toCashDirection(payload?.direction),
    origin: toCashOrigin(payload?.origin),
    transactionCount: Number(payload?.transaction_count ?? 0),
    amount: toMoney(payload?.amount),
  };
}

/** One row of `metrics.movements` → {@link CashMovementRow}. */
function toCashMovementRow(payload: CashMovementRowPayload): CashMovementRow {
  return {
    id: Number(payload?.id ?? 0),
    occurredAt: toLocalTimestamp(payload?.occurred_at),
    // `cash_registers` has no name column, so the register is identified by id and
    // no label is invented for it here or on the card.
    registerId: Number(payload?.cash_register_id ?? 0),
    type: String(payload?.type ?? ""),
    label: toText(payload?.label) ?? String(payload?.type ?? ""),
    direction: toCashDirection(payload?.direction),
    origin: toCashOrigin(payload?.origin),
    // A magnitude: the stored amount is unsigned and direction is a separate field,
    // so signing it here would invent a convention the schema does not have. The
    // card renders the sign from `direction`.
    amount: toMoney(payload?.amount),
    reference:
      payload?.reference_type === null || payload?.reference_type === undefined
        ? null
        : toReferenceLabel(payload.reference_type),
    note: toText(payload?.note),
    // `null` when the operator was absent or is out of tenant — the movement is
    // still reported, the person behind it simply is not disclosed.
    operator: payload?.created_by ? toText(payload.created_by.name) : null,
  };
}

/**
 * `GET /reports/cash-register-movements` → {@link CashRegisterMovementReport}.
 *
 * Every figure is read straight off the payload. In particular the **net movement
 * is the API's own `net_cash_movement`**, not `totalCashIn - totalCashOut`
 * recomputed here: the server states that identity and already holds both halves in
 * fixed-point, so re-deriving it in floating point would be a second implementation
 * free to drift from the first.
 *
 * Nothing is summed across `by_type` or `movements` either. Both lists describe the
 * same population as the header, so the client has no reason to produce a competing
 * total for them — and summing them here would be exactly the aggregation the API
 * already did, in a weaker number format.
 */
export function toCashRegisterMovementReport(
  payload: CashRegisterMovementReportPayload,
): CashRegisterMovementReport {
  const metrics = payload?.metrics;

  return {
    period: toPeriod(payload?.period),
    totalCashIn: toMoney(metrics?.total_cash_in),
    totalCashOut: toMoney(metrics?.total_cash_out),
    netCashMovement: toMoney(metrics?.net_cash_movement),
    transactionCount: Number(metrics?.transaction_count ?? 0),
    cashMovementCount: Number(metrics?.cash_movement_count ?? 0),
    nonMovementCount: Number(metrics?.non_movement_count ?? 0),
    // Both lists are read in the order the API sent them: the breakdown is
    // largest-first with a type tie-break, and the listing is `created_at` then `id`
    // descending. Both are deterministic server-side orderings, and client-side
    // re-sorting could only reproduce them approximately.
    byType: (Array.isArray(metrics?.by_type) ? metrics.by_type : []).map(
      toCashMovementBreakdown,
    ),
    movements: (Array.isArray(metrics?.movements) ? metrics.movements : []).map(
      toCashMovementRow,
    ),
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
    todayReturnsCount: Number(payload?.today_returns_count ?? 0),
    todayReturnsAmount: toMoney(payload?.today_returns_amount),
    todayPurchaseCount: Number(payload?.today_purchase_count ?? 0),
    todayPurchaseTotal: toMoney(payload?.today_purchase_total),
    todayExpenseCount: Number(payload?.today_expense_count ?? 0),
    todayTotalExpense: toMoney(payload?.today_total_expense),
    currentCashBalance: toMoney(payload?.current_cash_balance),
    lowStockCount: Number(payload?.low_stock_count ?? 0),
  };
}
