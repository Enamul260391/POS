/**
 * Report API payloads and the domain types the `/reports` screen renders.
 *
 * Source of truth: `App\Http\Controllers\Api\V1\ReportController` and
 * `App\Services\Report\ReportService`. Every one of the report endpoints
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
 *
 * `customer-outstanding` carries a `period` wrapper but is **not** a period
 * report: only `period_movement` is windowed. Its balances are standing
 * positions over the whole ledger and must not be read as though the date
 * filter narrowed them.
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
  /** Gross revenue — `SUM(sales.grand_total)`, gross of any returns. */
  total_sales: string;
  subtotal: string;
  discount: string;
  tax: string;
  /** `total_sales - returns_amount`: revenue after the period's returns.
   *  `grand_total` is already net of the header discount and inclusive of tax,
   *  so the API never applies them twice; the only subtraction here is returns. */
  net_sales: string;
  /** Completed sale returns dated inside the period. */
  returns_count: number;
  /** `SUM(sale_returns.refund_amount)` for those returns. */
  returns_amount: string;
  paid_amount: string;
  /** `SUM(sales.due_amount)` — what the invoices themselves recorded as owing.
   *  This is *not* the customer balance: a return never revises it. */
  due_amount: string;
  /** `SUM(customer_ledger_entries.amount)` — what customers actually owe now.
   *  The ledger is the receivable's source of truth; positive means owed to the
   *  business, negative means the business owes the customer. */
  customer_outstanding: string;
  /** Inbound payments of the matched invoices, grouped by method, largest
   *  first. A refund is never one of these — it is money going out. */
  payment_breakdown: SalesPaymentBreakdownPayload[];
  /** Refund payments that settled the period's returns. */
  refund_count: number;
  /** `SUM(payments.amount)` over those refunds. */
  refund_amount: string;
  /** Those refunds grouped by method, largest first — the outbound mirror of
   *  `payment_breakdown`. */
  refund_breakdown: SalesRefundBreakdownPayload[];
  /** `paid_amount - refund_amount`: what the business kept. */
  net_collected_amount: string;
  /**
   * `metrics.daily_series` — one point per calendar day of the requested
   * window, gross of returns.
   *
   * Two properties the axis depends on, both stated by the API rather than by
   * the client:
   *
   *  - **Contiguous.** Every day between `period.from` and `period.to` is
   *    present, including days with no sales, which arrive as `"0.00"`. A
   *    chart that skipped them would compress the period and misreport its shape.
   *  - **Gross.** Each point is the same reading as `total_sales` — returns and
   *    refunds never reduce it — so the points sum to that figure.
   *
   * Empty only when the window is open on one side (`date_from` or `date_to`
   * absent): there is no calendar to span, so only days carrying sales are
   * returned. That is not an error and never means "no sales".
   */
  daily_series: DailySalesPointPayload[];
  /**
   * `metrics.monthly_series` — one point per calendar month the requested
   * window touches, gross of returns.
   *
   * The same two properties as {@link daily_series}, at month granularity:
   *
   *  - **Contiguous.** Every month from `period.from` to `period.to` is present,
   *    including months with no sales, which arrive as `"0.00"`. A chart that
   *    skipped them would compress the period and misreport its shape. Months the
   *    window only partly covers are included — a range of `2026-01-15 … 2026-04-10`
   *    is four months long, and January is the month the sales actually fell in.
   *  - **Gross.** Each point is the same reading as `total_sales` — returns and
   *    refunds never reduce it — so the points sum to that figure exactly.
   *
   * Empty only when the window is open on one side (`date_from` or `date_to`
   * absent): there is no calendar to span, so only months carrying sales are
   * returned, with no zeros for the gap between them. That is not an error and
   * never means "no sales".
   */
  monthly_series: MonthlySalesPointPayload[];
  /**
   * `metrics.top_products` — the best sellers of the window, largest
   * gross revenue first, capped at the API's own top-N.
   *
   * Four properties the card depends on, all decided by the API:
   *
   *  - **Gross.** Each row is the same reading as `total_sales` —
   *    `SUM(sale_items.total)` — so returns and refunds never reduce it. The
   *    net figures live on their own fields beside it.
   *  - **Ranked by revenue, not by units.** The order is
   *    `total_sales DESC, product_id ASC`; the `product_id` tie-break is what
   *    makes the order stable between two identical requests.
   *  - **Bounded.** Only the top N come back, so this is a leaderboard rather
   *    than a catalogue, and the client never trims it.
   *  - **Identifiable products only.** `sale_items.product_id` is nullable for a
   *    service line, and such a line is excluded rather than filed under a
   *    placeholder — there is no "Unknown" row to render.
   *
   * Empty when the window had no product lines at all. That is a real answer,
   * not an error and not a missing field.
   */
  top_products: TopProductPayload[];
  /**
   * `metrics.top_categories` — the best-selling categories of the window,
   * largest gross revenue first, capped at the API's own top-N.
   *
   * The same four properties as {@link top_products}, one join further down
   * (`sale_items -> products -> categories`):
   *
   *  - **Gross.** Each row is `SUM(sale_items.total)` over the same invoices
   *    `total_sales` counts, so returns and refunds never reduce it. The net
   *    figures live on their own fields beside it.
   *  - **Ranked by revenue, not by units.** The order is
   *    `total_sales DESC, category_id ASC`; the `category_id` tie-break is what
   *    makes the order stable between two identical requests.
   *  - **Bounded.** Only the top N come back, so this is a leaderboard rather
   *    than the tenant's whole category tree, and the client never trims it.
   *  - **Identifiable categories only.** A service line has no product, and a
   *    product with a null `category_id` has no category; both are excluded
   *    rather than filed under a placeholder. The schema stores no reserved
   *    "Uncategorized" row, so there is no such row to render.
   *
   * Empty when the window had no categorised product lines at all — including
   * the case where sales exist but none of them can name a category. That is a
   * real answer, not an error and not a missing field.
   */
  top_categories: TopCategoryPayload[];
  /**
   * `metrics.top_customers` — the customers who bought most in the window,
   * largest gross revenue first, capped at the API's own top-N.
   *
   * Four properties the card depends on, all decided by the API:
   *
   *  - **Gross.** Each row is `SUM(sales.grand_total)` over the same invoices
   *    `total_sales` counts, so returns and refunds never reduce it. The net
   *    figures live on their own fields beside it.
   *  - **Ranked by revenue.** The order is `total_sales DESC, customer_id ASC`;
   *    the `customer_id` tie-break is what makes the order stable between two
   *    identical requests.
   *  - **Bounded.** Only the top N come back, so this is a leaderboard rather
   *    than the whole customer book, and the client never trims it.
   *  - **Invoices, not lines.** `invoice_count` is `COUNT(sales.id)`, so one
   *    invoice is one row however many lines it has or however it was paid.
   *
   * **It does not reconcile with `total_sales`, and that is not an error.** A
   * sale may have no customer at all — a walk-in — and the schema holds no
   * reserved "Walk-in Customer" row, so those invoices are counted in
   * `total_sales` and necessarily absent here. Whenever the window contains any
   * such sale, the ranking sums to *less* than the total, by exactly that money.
   * A client that "reconciled" them by inventing a walk-in row would be
   * reporting a customer the server never sent.
   *
   * Empty either because the window had no sales at all, or because it had sales
   * but none could be attributed to a customer. Both are real answers, and the
   * card words them differently.
   */
  top_customers: TopCustomerPayload[];
  /**
   * `metrics.top_users` — the staff who sold most in the window, largest gross
   * revenue first, capped at the API's own top-N.
   *
   * Four properties the card depends on, all decided by the API:
   *
   *  - **Gross.** Each row is `SUM(sales.grand_total)` over the same invoices
   *    `total_sales` counts, so returns and refunds never reduce it. The net
   *    figures live on their own fields beside it.
   *  - **Ranked by revenue.** The order is `total_sales DESC, user_id ASC`; the
   *    `user_id` tie-break is what makes the order stable between two identical
   *    requests.
   *  - **Bounded.** Only the top N come back, so this is a leaderboard rather
   *    than the whole staff directory, and the client never trims it.
   *  - **Invoices, not lines.** `invoice_count` is `COUNT(sales.id)`, so one
   *    invoice is one row however many lines it has or however it was paid.
   *
   * **It does not reconcile with `total_sales`, and that is not an error.** The
   * user is the one recorded on the sale itself (`sales.cashier_id`, the
   * relationship the POS already writes), and that column is nullable — an
   * unassigned sale is counted in `total_sales` and necessarily absent here.
   * The schema holds no reserved "Unknown User" row, so none is rendered.
   *
   * Empty either because the window had no sales at all, or because it had sales
   * but none could be attributed to a staff member. Both are real answers, and
   * the card words them differently.
   */
  top_users: TopUserPayload[];
}

/** One row of `metrics.top_products`. */
export interface TopProductPayload {
  /** The product's own id — stable, and what ties are broken on. */
  product_id: number;
  /** `products.name`, verbatim. */
  product_name: string;
  /** `SUM(sale_items.quantity)` over the period — the ledger's `decimal(15,4)`,
   *  normalised by `Quantity::format`, so `"5"` or `"2.5"`. */
  quantity: string;
  /** `SUM(sale_items.total)` over the period, gross of returns and refunds,
   *  through `Money::format`. Always a **string** (`"2500.00"`). */
  total_sales: string;
}

/** One row of `metrics.top_categories`. */
export interface TopCategoryPayload {
  /** The category's own id — stable, and what ties are broken on. */
  category_id: number;
  /** `categories.name`, verbatim. Never a placeholder: the API only ever sends a
   *  row for a category it actually joined. */
  category_name: string;
  /** `SUM(sale_items.quantity)` over the period, across every product in the
   *  category — the ledger's `decimal(15,4)`, normalised by `Quantity::format`. */
  quantity: string;
  /** `SUM(sale_items.total)` over the period, gross of returns and refunds,
   *  through `Money::format`. Always a **string** (`"7200.00"`). */
  total_sales: string;
}

/** One point of `metrics.daily_series`. */
export interface DailySalesPointPayload {
  /** The calendar day, `YYYY-MM-DD` — the invoice's own `sale_date`. */
  date: string;
  /** `SUM(sales.grand_total)` for that day, gross of returns. `"0.00"` for a
   *  day with no sales. */
  total_sales: string;
}

/** One point of `metrics.monthly_series`. */
export interface MonthlySalesPointPayload {
  /**
   * The calendar month, `YYYY-MM`.
   *
   * A **calendar identifier**, not a timestamp: it is kept verbatim through the
   * mapper and never round-tripped through `new Date(...)`, which would parse it
   * as UTC and could shift the label a month backwards for some viewers.
   */
  month: string;
  /** `SUM(sales.grand_total)` for that month, gross of returns. `"0.00"` for a
   *  month with no sales. */
  total_sales: string;
}

/** One row of `metrics.payment_breakdown`. */
export interface SalesPaymentBreakdownPayload {
  /** A `Payment::PAYMENT_METHODS` value. */
  payment_method: string;
  /** How many payment rows carried this method. */
  payment_count: number;
  /** `SUM(payments.amount)` for the method. */
  total_amount: string;
}

/** One row of `metrics.refund_breakdown`. */
export interface SalesRefundBreakdownPayload {
  /** A `Payment::REFUND_METHODS` value — `due` is never a refund method. */
  payment_method: string;
  /** How many refund rows carried this method. */
  refund_count: number;
  /** `SUM(payments.amount)` refunded through this method. */
  refund_amount: string;
}

/* ---------------------------------------------------------------------------
 * GET /reports/customer-outstanding
 * ------------------------------------------------------------------------- */

/**
 * One row of `metrics.customers`.
 *
 * **`balance` is the customer ledger's, never the invoice's.** It is
 * `SUM(customer_ledger_entries.amount)` over that customer's whole history —
 * the same source of truth the ledger itself is written from. `due_amount` is
 * *not* this figure: a later payment revises the invoice column, a later return
 * does not, so summing it would report money the business already holds.
 *
 * The sign is the ledger's own convention and is preserved verbatim rather than
 * made absolute: **positive means the customer owes the business, negative means
 * the business owes the customer.** A negative row is an advance or credit the
 * business holds, not a fabricated negative receivable — which is why `status`
 * is sent by the server rather than left for the client to infer from the sign.
 */
export interface CustomerOutstandingRowPayload {
  /** The customer's own id — stable, and the ordering tie-break. */
  customer_id: number;
  /** `customers.name`, verbatim. Never a placeholder: the API only ever sends a
   *  row for a customer it joined *and* could still name, pinned to the caller's
   *  own business, so neither a soft-deleted nor a cross-tenant customer appears. */
  customer_name: string;
  /** `SUM(customer_ledger_entries.amount)`, signed, through `Money::format`.
   *  Always a **string** (`"500.00"`, `"-750.00"`). */
  balance: string;
  /** The API's own classification: `"receivable"` or `"customer_credit"`. Sent
   *  rather than derived so the client never has to test a sign to decide which
   *  side of the ledger a row belongs to. */
  status: string;
  /** How much *this customer's* ledger moved inside the requested window. The
   *  only figure the date filter is allowed to change; never a balance. */
  period_movement: string;
}

/** `ReportService::customerOutstandingReport()` metrics, verbatim. */
export interface CustomerOutstandingMetricsPayload {
  /** Customers whose balance is not exactly zero — exactly the rows below. */
  customer_count: number;
  /** Sum of the **positive** balances: money owed to the business. */
  receivable: string;
  /** **Magnitude** of the negative balances: money the business owes back. Never
   *  reported as a negative receivable, and never dropped. */
  customer_credit: string;
  /** `receivable - customer_credit`. The net position, which is negative when the
   *  business owes more than it is owed. */
  net_outstanding: string;
  /** The whole ledger's movement inside the window. With no window sent this is
   *  the whole-history movement, which is the honest reading of an unbounded
   *  request rather than a default period. */
  period_movement: string;
  /** Only customers with a non-zero balance; the settled are filtered out in SQL
   *  by `HAVING balance != 0` and contribute nothing to either total. */
  customers: CustomerOutstandingRowPayload[];
}

/** `data` of `GET /reports/customer-outstanding`. */
export interface CustomerOutstandingReportPayload {
  period: ReportPeriodPayload;
  metrics: CustomerOutstandingMetricsPayload;
}

/** Which side of the ledger one customer stands on. */
export type CustomerOutstandingStatus = "receivable" | "customer_credit";

/** One customer row, mapped. */
export interface CustomerOutstandingRow {
  /** `customer_id`, preserved exactly — it is the row's key. */
  customerId: number;
  /** `customer_name` — `customers.name` as the server read it. */
  customerName: string;
  /** `balance`, **signed**: negative for a customer the business owes. Kept as
   *  the ledger states it rather than made absolute, so the row cannot contradict
   *  the ledger it came from. */
  balance: number;
  /** `status`, narrowed from the API's own vocabulary. Anything unrecognised falls
   *  back to `"receivable"`, which is the safer of the two readings: a balance is
   *  only ever negative because the business owes the customer, so guessing
   *  "receivable" for an unlabelled row would misread a credit as a debt. */
  status: CustomerOutstandingStatus;
  /** `period_movement` — the dated movement, never the balance. */
  periodMovement: number;
}

/**
 * The customer outstanding / receivables report as the UI renders it.
 *
 * **This is a position report, not a period report.** `receivable`,
 * `customerCredit` and `netOutstanding` are standing balances over the whole
 * ledger and do **not** move when the date filter changes — a payment made last
 * month reduces the debt today whether or not it falls inside the window. Only
 * `periodMovement` is windowed, and it is named so the two can never be confused.
 */
export interface CustomerOutstandingReport {
  period: ReportPeriod;
  /** `metrics.customer_count` — customers with a non-zero balance. */
  customerCount: number;
  /** `metrics.receivable` — what customers owe the business. */
  receivable: number;
  /** `metrics.customer_credit` — what the business owes customers, as a
   *  magnitude. */
  customerCredit: number;
  /** `metrics.net_outstanding` — `receivable - customerCredit`. */
  netOutstanding: number;
  /** `metrics.period_movement` — the ledger's movement inside the window. */
  periodMovement: number;
  /** `metrics.customers` — the listing, ordered by the API. */
  customers: CustomerOutstandingRow[];
}

/* ---------------------------------------------------------------------------
 * GET /reports/supplier-outstanding
 * ------------------------------------------------------------------------- */

/**
 * One row of `metrics.suppliers`.
 *
 * **`balance` is the purchase flow's, never a client-side sum.** It is
 * `suppliers.opening_balance + SUM(purchases.due_amount)` for that supplier over
 * the whole history. The `due_amount` header column is the source of truth here
 * because the purchase flow rewrites it on every write that moves it
 * (`due = grand_total - paid_amount`), which is what distinguishes it from the
 * customer side's invoice column.
 *
 * The sign is the arithmetic's own and is preserved verbatim rather than made
 * absolute: **positive means the business owes the supplier, negative means the
 * supplier owes the business.** A negative row is credit the business holds, not
 * a fabricated negative payable — which is why `status` is sent by the server
 * rather than left for the client to infer from the sign.
 */
export interface SupplierOutstandingRowPayload {
  /** The supplier's own id — stable, and the ordering tie-break. */
  supplier_id: number;
  /** `suppliers.name`, verbatim. Never a placeholder: the API only ever sends a
   *  row for a supplier it scoped to the caller's own business and that is not
   *  soft-deleted, so neither a deleted nor a cross-tenant supplier appears. */
  supplier_name: string;
  /** `opening_balance + SUM(purchases.due_amount)`, signed, through
   *  `Money::format`. Always a **string** (`"500.00"`, `"-250.00"`). */
  balance: string;
  /** The API's own classification: `"payable"` or `"supplier_credit"`. Sent
   *  rather than derived so the client never has to test a sign to decide which
   *  side of the account a row belongs to. */
  status: string;
  /** How much *this supplier's* invoices moved inside the requested window. The
   *  only figure the date filter is allowed to change; never a balance. */
  period_movement: string;
}

/** `ReportService::supplierOutstandingReport()` metrics, verbatim. */
export interface SupplierOutstandingMetricsPayload {
  /** Suppliers whose balance is not exactly zero — exactly the rows below. */
  supplier_count: number;
  /** Sum of the **positive** balances: money owed to suppliers. */
  payable: string;
  /** **Magnitude** of the negative balances: credit held from suppliers. Never
   *  reported as a negative payable, and never dropped. */
  supplier_credit: string;
  /** `payable - supplier_credit`. The net position, which is negative when the
   *  business is owed more than it owes. */
  net_outstanding: string;
  /** The movement inside the window. With no window sent this is the whole-history
   *  movement, which is the honest reading of an unbounded request rather than a
   *  default period. */
  period_movement: string;
  /** Only suppliers with a non-zero balance; the settled are filtered out in SQL
   *  by `HAVING balance != 0` and contribute nothing to either total. */
  suppliers: SupplierOutstandingRowPayload[];
}

/** `data` of `GET /reports/supplier-outstanding`. */
export interface SupplierOutstandingReportPayload {
  period: ReportPeriodPayload;
  metrics: SupplierOutstandingMetricsPayload;
}

/** Which side of the account one supplier stands on. */
export type SupplierOutstandingStatus = "payable" | "supplier_credit";

/** One supplier row, mapped. */
export interface SupplierOutstandingRow {
  /** `supplier_id`, preserved exactly — it is the row's key. */
  supplierId: number;
  /** `supplier_name` — `suppliers.name` as the server read it. */
  supplierName: string;
  /** `balance`, **signed**: negative for credit the business holds from the
   *  supplier. Kept as the arithmetic states it rather than made absolute, so the
   *  row cannot contradict the figure it came from. */
  balance: number;
  /** `status`, narrowed from the API's own vocabulary. Anything unrecognised
   *  falls back to `"payable"`, which is the safer of the two readings: a balance
   *  is only ever negative because the supplier owes the business, so guessing
   *  "payable" for an unlabelled row would misread a credit as a debt. */
  status: SupplierOutstandingStatus;
  /** `period_movement` — the dated movement, never the balance. */
  periodMovement: number;
}

/**
 * The supplier outstanding / payables report as the UI renders it.
 *
 * **This is a position report, not a period report.** `payable`, `supplierCredit`
 * and `netOutstanding` are standing balances over the whole history and do
 * **not** move when the date filter changes — an invoice paid off last month is
 * no longer owed today whether or not it falls inside the window. Only
 * `periodMovement` is windowed, and it is named so the two can never be confused.
 */
export interface SupplierOutstandingReport {
  period: ReportPeriod;
  /** `metrics.supplier_count` — suppliers with a non-zero balance. */
  supplierCount: number;
  /** `metrics.payable` — what the business owes its suppliers. */
  payable: number;
  /** `metrics.supplier_credit` — what suppliers owe the business, as a
   *  magnitude. */
  supplierCredit: number;
  /** `metrics.net_outstanding` — `payable - supplierCredit`. */
  netOutstanding: number;
  /** `metrics.period_movement` — the payables raised inside the window. */
  periodMovement: number;
  /** `metrics.suppliers` — the listing, ordered by the API. */
  suppliers: SupplierOutstandingRow[];
}

/** `data` of `GET /reports/sales`. */
export interface SalesReportPayload {
  period: ReportPeriodPayload;
  metrics: SalesReportMetricsPayload;
}

/**
 * The sales summary as the UI renders it.
 *
 * This report carries no cost of goods, so it has no profit line of its own:
 * every cost-derived figure belongs to `GET /reports/profit-loss`, which owns
 * `unit_cost` and is therefore the only place gross profit can come from. The
 * card that displays gross profit takes it from there rather than deriving one.
 */
export interface SalesReport {
  period: ReportPeriod;
  /** `metrics.sale_count`. */
  invoiceCount: number;
  /** `metrics.total_sales` — the invoice grand total, already net of discount and
   *  **gross of returns**. */
  grossSales: number;
  /** `metrics.subtotal`. */
  subtotal: number;
  /** `metrics.discount`. */
  discountTotal: number;
  /** `metrics.tax`. */
  taxTotal: number;
  /** `metrics.returns_count` — completed returns dated in the period. */
  returnsCount: number;
  /** `metrics.returns_amount` — the revenue reversal. */
  returnsAmount: number;
  /** `metrics.net_sales` — `grossSales - returnsAmount`. */
  netSales: number;
  /** `metrics.paid_amount` — money actually collected, inbound only. */
  paidAmount: number;
  /** `metrics.due_amount` — what the invoices recorded as owing. Not the
   *  customer balance: a return never revises it. */
  dueAmount: number;
  /** `metrics.customer_outstanding` — the ledger's balance. This, not `dueAmount`,
   *  is what customers actually owe. */
  customerOutstanding: number;
  /**
   * `metrics.payment_breakdown` — the API's own per-method split of the
   * payments behind those invoices, largest amount first.
   *
   * Empty only when no invoice in the window recorded a payment; the API never
   * synthesises a row, so an empty list means "no payments", not "unknown".
   * A refund is never one of these rows — it is money going out.
   */
  byPaymentMethod: SalesPaymentBreakdown[];
  /** `metrics.refund_count` — refund payments settling this period's returns. */
  refundCount: number;
  /** `metrics.refund_amount` — money paid back out. */
  refundAmount: number;
  /** `metrics.refund_breakdown` — the outbound mirror of `byPaymentMethod`. */
  byRefundMethod: SalesRefundBreakdown[];
  /** `metrics.net_collected_amount` — `paidAmount - refundAmount`. */
  netCollectedAmount: number;
  /**
   * `metrics.daily_series` — the per-day trend, in calendar order.
   *
   * One entry per day of the requested window, with the quiet days present at
   * zero. Nothing is derived: the API already grouped, filled and ordered the
   * axis, so this is the same list with the wire format converted.
   */
  dailySales: DailySalesPoint[];
  /**
   * `metrics.monthly_series` — the per-month trend, in calendar order.
   *
   * One entry per month of the requested window, with the quiet months present
   * at zero, exactly as for {@link dailySales}. The API already grouped, filled
   * and ordered the axis, so this is the same list with the wire format
   * converted — nothing is bucketed, summed or re-derived here.
   */
  monthlySales: MonthlySalesPoint[];
  /**
   * `metrics.top_products` — the best sellers, already ranked and capped by
   * the API.
   *
   * Kept in exactly the order the API sent: the ordering *is* the report, and
   * re-sorting it in the browser would be the client deciding a ranking the
   * server owns. The list may be shorter than the top N, and is empty when the
   * window held no product lines.
   */
  topProducts: TopProduct[];
  /**
   * `metrics.top_categories` — the best-selling categories, already ranked and
   * capped by the API.
   *
   * Kept on exactly the same terms as {@link topProducts}: the order is the
   * report and the server owns it, so nothing is re-sorted or trimmed here. The
   * list is empty when the window held no line that could name a category —
   * including when sales existed but none of them were categorised.
   */
  topCategories: TopCategory[];
  /**
   * `metrics.top_customers` — the customers who bought most, already ranked and
   * capped by the API.
   *
   * Kept on exactly the same terms as {@link topProducts}: the order is the
   * report and the server owns it, so nothing is re-sorted or trimmed here.
   *
   * It is deliberately **not** a partition of {@link grossSales}. A sale with no
   * customer is still real revenue and still counted there, so the list can sum
   * to less than the total — by exactly the walk-in money. No walk-in row is
   * synthesised to close that gap, because the server never sent one and a
   * fabricated customer in a leaderboard is worse than a visible shortfall.
   */
  topCustomers: TopCustomer[];
  /**
   * `metrics.top_users` — the staff who sold most, already ranked and capped by
   * the API.
   *
   * Kept on exactly the same terms as {@link topProducts}: the order is the
   * report and the server owns it, so nothing is re-sorted or trimmed here.
   *
   * It is deliberately **not** a partition of {@link grossSales}. A sale with no
   * cashier recorded is still real revenue and still counted there, so the list
   * can sum to less than the total — by exactly the unassigned money. No
   * placeholder row is synthesised to close that gap, because the server never
   * sent one and a fabricated staff member in a leaderboard is worse than a
   * visible shortfall.
   */
  topUsers: TopUser[];
}

/** One row of the sales-by-customer leaderboard. */
export interface TopCustomer {
  /** `customer_id`, preserved exactly — it is the row's key and the tie-break. */
  customerId: number;
  /** `customer_name` — `customers.name` as the server read it. */
  customerName: string;
  /** `invoice_count` — matching invoices. One invoice counts once however many
   *  lines it has or however many payments settled it. */
  invoiceCount: number;
  /** `total_sales` — gross revenue, never reduced by returns or refunds. */
  totalSales: number;
}

/** One row of the sales-by-staff leaderboard. */
export interface TopUser {
  /** `user_id`, preserved exactly — it is the row's key and the tie-break. */
  userId: number;
  /** `user_name` — `users.name` as the server read it. */
  userName: string;
  /** `invoice_count` — invoices this staff member served. One invoice counts once
   *  however many lines it has or however many payments settled it. */
  invoiceCount: number;
  /** `total_sales` — gross revenue, never reduced by returns or refunds. */
  totalSales: number;
}

/** One row of `metrics.top_users`. */
export interface TopUserPayload {
  /** The user's own id — stable, and what ties are broken on. */
  user_id: number;
  /** `users.name`, verbatim. Never a placeholder: the API only ever sends a row
   *  for a user it actually joined, pinned to the caller's own business, so a
   *  cross-tenant staff name never appears. */
  user_name: string;
  /** `COUNT(sales.id)` over the period — matching **invoices**, not sale lines
   *  and not payments. */
  invoice_count: number;
  /** `SUM(sales.grand_total)` over the period, gross of returns and refunds,
   *  through `Money::format`. Always a **string** (`"8500.00"`). */
  total_sales: string;
}

/** One row of the top-products leaderboard. */
export interface TopProduct {
  /** `product_id`, preserved exactly — it is the row's key and the tie-break. */
  productId: number;
  /** `product_name` — `products.name` as the server read it. */
  productName: string;
  /** `quantity` — units sold, at the ledger's four-digit scale. */
  quantity: number;
  /** `total_sales` — gross revenue, never reduced by returns or refunds. */
  totalSales: number;
}

/** One row of the sales-by-category leaderboard. */
export interface TopCategory {
  /** `category_id`, preserved exactly — it is the row's key and the tie-break. */
  categoryId: number;
  /** `category_name` — `categories.name` as the server read it. */
  categoryName: string;
  /** `quantity` — units sold across every product in the category, at the
   *  ledger's four-digit scale. */
  quantity: number;
  /** `total_sales` — gross revenue, never reduced by returns or refunds. */
  totalSales: number;
}

/** One row of `metrics.top_customers`. */
export interface TopCustomerPayload {
  /** The customer's own id — stable, and what ties are broken on. */
  customer_id: number;
  /** `customers.name`, verbatim. Never a placeholder: the API only ever sends a
   *  row for a customer it actually joined and could still name, so a soft
   *  deleted or cross-tenant customer never appears. */
  customer_name: string;
  /** `COUNT(sales.id)` over the period — matching **invoices**, not sale lines
   *  and not payments. */
  invoice_count: number;
  /** `SUM(sales.grand_total)` over the period, gross of returns and refunds,
   *  through `Money::format`. Always a **string** (`"4250.00"`). */
  total_sales: string;
}

/** One day of the daily sales trend. */
export interface DailySalesPoint {
  /** The calendar day, `YYYY-MM-DD`. */
  date: string;
  /** The day's gross sales — the same reading as {@link SalesReport.grossSales},
   *  never reduced by that day's returns. */
  totalSales: number;
}

/** One month of the monthly sales trend. */
export interface MonthlySalesPoint {
  /**
   * The calendar month, `YYYY-MM`, kept exactly as the API sent it.
   *
   * A calendar identifier rather than a `Date`: the card renders it and the
   * report groups by it, and neither needs an instant. Passing it through
   * `new Date("2026-01")` would resolve to UTC and could label the month
   * December for a viewer west of Greenwich.
   */
  month: string;
  /** The month's gross sales — the same reading as
   *  {@link SalesReport.grossSales}, never reduced by that month's returns. */
  totalSales: number;
}

/** One payment method in the sales refund breakdown. */
export interface SalesRefundBreakdown {
  /** The API's own `payment_method` value, e.g. `"cash"`. Never `"due"`. */
  method: string;
  /** Label for the row — the same stored value. */
  label: string;
  /** `refund_amount` for this method. */
  amount: number;
  /** Share of `metrics.refund_amount`, two decimals. */
  percent: number;
  /** `refund_count` — how many refund rows carried this method. */
  refundCount: number;
}

/** One payment method in the sales payment breakdown. */
export interface SalesPaymentBreakdown {
  /** The API's own `payment_method` value, e.g. `"cash"`. */
  method: string;
  /** The same value as the label to render, in the API's vocabulary. */
  label: string;
  /** `metrics[].total_amount` — the method's share of the money collected. */
  amount: number;
  /** Share of the breakdown's own total, for the bar beside the amount. */
  percent: number;
  /** `metrics[].payment_count` — how many payments carried this method. */
  paymentCount: number;
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

/**
 * `ReportService::profitLoss()` metrics, verbatim.
 *
 * `available` is driven by the data, not by a missing column: every cost-derived
 * figure is present when `available` is true and is `null` when it is false, so
 * a client can never accidentally treat a withheld figure as a real zero.
 */
export interface ProfitLossMetricsPayload {
  /**
   * `true` when every sale line in the period carries a recorded unit cost.
   * `false` means `unpriced_lines > 0`, so no honest profit can be produced.
   */
  available: boolean;
  /** Explains a withheld figure in the APIs own words; `null` when available. */
  limitation: string | null;
  /** `SUM(grand_total)` over the matched invoices — gross of returns. */
  revenue: string;
  discount: string;
  tax: string;
  /** Completed returns dated inside the period. */
  returns_count: number;
  /** `SUM(sale_returns.refund_amount)` — the revenue reversal. */
  returns_amount: string;
  /** `revenue - returns_amount`. */
  net_revenue: string;
  /** `SUM(unit_cost * quantity)`; `null` when `available` is false. */
  cost_of_goods: string | null;
  /** `SUM(sale_items.unit_cost * returned quantity)` over the period's returns —
   *  the cost reversal, read from the historical unit cost rather than today's
   *  product cost. `null` when `available` is false. */
  returned_cost_of_goods: string | null;
  /** `cost_of_goods - returned_cost_of_goods`; `null` when unavailable. */
  net_cost_of_goods: string | null;
  /** `revenue - cost_of_goods`; `null` when `available` is false. */
  gross_profit: string | null;
  /** `net_revenue - net_cost_of_goods`; `null` when `available` is false. */
  net_gross_profit: string | null;
  /** `null` when unavailable, and also when revenue is zero (no margin exists). */
  gross_margin_percent: string | null;
  /** `GET /reports/expenses`' `total_expense` for the same window. */
  total_expense: string;
  /** `net_gross_profit - total_expense`; `null` when `available` is false. */
  net_profit: string | null;
  /** How many sale lines hold the profit figures back. A returned line whose
   *  sale line had no recorded cost counts too, since reversing an unknown cost
   *  would invent a number. */
  unpriced_lines: number;
}

/** `data` of `GET /reports/profit-loss`. */
export interface ProfitLossReportPayload {
  period: ReportPeriodPayload;
  metrics: ProfitLossMetricsPayload;
}

/**
 * The profit/loss answer for the window.
 *
 * Every cost-derived figure is `number | null`, and the API withholds it
 * (`null`) whenever `available` is false — because at least one sale line in
 * the period has no recorded unit cost. Reading `null` as `0` would show a
 * profit that silently treats unpriced lines as free, so the UI renders a dash
 * and prints `limitation` verbatim instead.
 *
 * Revenue and expenses are *not* cost-derived: they are reported even when the
 * profit figures are withheld, because they are independently true.
 */
export interface ProfitLossReport {
  period: ReportPeriod;
  /** `metrics.available`. */
  available: boolean;
  /** `metrics.limitation`. */
  limitation: string | null;
  /** `metrics.revenue` — gross of returns. */
  revenue: number;
  discount: number;
  tax: number;
  /** `metrics.returns_count`. */
  returnsCount: number;
  /** `metrics.returns_amount` — the revenue reversal. */
  returnsAmount: number;
  /** `metrics.net_revenue`. */
  netRevenue: number;
  /** `metrics.cost_of_goods`, or `null` when unavailable. */
  costOfGoods: number | null;
  /** `metrics.returned_cost_of_goods`, or `null` when unavailable. */
  returnedCostOfGoods: number | null;
  /** `metrics.net_cost_of_goods`, or `null` when unavailable. */
  netCostOfGoods: number | null;
  /** `metrics.gross_profit`, or `null` when unavailable. */
  grossProfit: number | null;
  /** `metrics.net_gross_profit`, or `null` when unavailable. */
  netGrossProfit: number | null;
  /** `metrics.gross_margin_percent`, or `null` when unavailable or revenue is 0. */
  grossMarginPercent: number | null;
  /** `metrics.total_expense` — always reported. */
  totalExpense: number;
  /** `metrics.net_profit`, or `null` when unavailable. */
  netProfit: number | null;
  /** `metrics.unpriced_lines` — sale lines withholding the profit figures. */
  unpricedLines: number;
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
  today_returns_count: number;
  today_returns_amount: string;
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
  /** `today_total_sales` — gross of returns. */
  todayTotalSales: number;
  /** `today_net_sales` — gross minus the day's returns. */
  todayNetSales: number;
  /** `today_returns_count`. */
  todayReturnsCount: number;
  /** `today_returns_amount`. */
  todayReturnsAmount: number;
  todayPurchaseCount: number;
  todayPurchaseTotal: number;
  todayExpenseCount: number;
  todayTotalExpense: number;
  currentCashBalance: number;
  lowStockCount: number;
}

/* ---------------------------------------------------------------------------
 * GET /reports/cash-register-movements
 * ------------------------------------------------------------------------- */

/**
 * One canonical `cash_register_transactions.type` value.
 *
 * Mirrors `App\Models\CashRegisterTransaction::TYPES` exactly. The column is a
 * plain `string(30)`, **not** an enum, and the API publishes whatever is stored —
 * so this union describes the values the application writes, not a database
 * constraint. An unrecognised stored value still renders (as its raw text) rather
 * than breaking the card.
 */
export type CashMovementType =
  | "opening"
  | "sale"
  | "expense"
  /** Cash handed back on a sale return. Automatic; never editable by hand. */
  | "refund"
  | "cash_in"
  | "cash_out"
  | "closing";

/**
 * Which way a movement moves the drawer.
 *
 * `non_movement` is a deliberate **third** value, not a third spelling of "out":
 * the `opening` float and the `closing` count are written by
 * `CashRegisterService::open()` / `close()` to mark the shift, and they are in
 * neither `INFLOW_TYPES` nor `OUTFLOW_TYPES`. The drawer balance is
 * `opening_balance + inflow − outflow`, so counting an opening float as an inflow
 * would add it twice and a closing count as an outflow would subtract money that
 * was never removed. The card must never sum the markers into a cash total.
 */
export type CashMovementDirection = "in" | "out" | "non_movement";

/**
 * Who wrote the movement: a human, a document flow, or the drawer itself.
 *
 * A *display* classification only. It restates boundaries the schema already
 * defines — `MANUAL_TYPES` is exactly what `recordManual()` writes,
 * `AUTOMATIC_TYPES` is exactly what `postAutomaticTransaction()` whitelists — so
 * no value is persisted for it and none is invented.
 */
export type CashMovementOrigin = "manual" | "automatic" | "non_movement";

/** `ReportService::cashRegisterMovements()` metrics, verbatim. */
export interface CashRegisterMovementMetricsPayload {
  /** `SUM(amount)` over `INFLOW_TYPES` rows in the window. */
  total_cash_in: string;
  /** `SUM(amount)` over `OUTFLOW_TYPES` rows in the window. */
  total_cash_out: string;
  /** `total_cash_in - total_cash_out`, stated as an identity. */
  net_cash_movement: string;
  /**
   * Every register row in the window, markers included.
   *
   * A count of *rows*, not of movements — which is why `cash_movement_count` and
   * `non_movement_count` sit beside it. The API publishes no "total collected
   * including card" figure, and the card never derives one.
   */
  transaction_count: number;
  /** `transaction_count - non_movement_count`. */
  cash_movement_count: number;
  /** Count of the `opening` / `closing` marker rows. */
  non_movement_count: number;
  by_type: CashMovementTypeBreakdownPayload[];
  movements: CashMovementRowPayload[];
}

/** One aggregate in `metrics.by_type`. */
export interface CashMovementTypeBreakdownPayload {
  /** The stored type, verbatim. */
  type: CashMovementType | string;
  /** Display label the API supplied for that type. */
  label: string;
  direction: CashMovementDirection;
  origin: CashMovementOrigin;
  transaction_count: number;
  /** Always a magnitude; the sign is carried by `direction`. */
  amount: string;
}

/** One row of `metrics.movements`. */
export interface CashMovementRowPayload {
  id: number;
  /**
   * The movement's own `created_at`, ISO 8601.
   *
   * This schema has no separate `occurred_at`, and the report does not invent one
   * from a joined document date — that would re-derive the movement from elsewhere.
   */
  occurred_at: string;
  cash_register_id: number;
  type: CashMovementType | string;
  label: string;
  direction: CashMovementDirection;
  origin: CashMovementOrigin;
  /** Always a magnitude; the sign is carried by `direction`. */
  amount: string;
  /**
   * Morph class of the document this row was written for, e.g. `"Payment"`.
   *
   * No column anywhere holds a human label for the referenced document, so no
   * description is manufactured from this — the UI renders the class basename.
   */
  reference_type: string | null;
  reference_id: number | null;
  /** Free text written by the API or the cashier. */
  note: string | null;
  /**
   * The operator, or `null` when there was none or the operator is out of tenant.
   *
   * Suppressed rather than omitted in that second case: the movement is still this
   * business's cash, only the person behind it is not disclosed.
   */
  created_by: { id: number; name: string } | null;
  register: {
    id: number;
    /** `open` / `closed`, or `null`. `cash_registers` has **no** name column. */
    status: string | null;
    opened_at: string | null;
    /**
     * The float the drawer was opened with — context only.
     *
     * Deliberately never added to `total_cash_in`: it is a starting balance, not a
     * collection, and the drawer service counts it separately.
     */
    opening_balance: string | null;
  };
}

/** `data` of `GET /reports/cash-register-movements`. */
export interface CashRegisterMovementReportPayload {
  period: ReportPeriodPayload;
  metrics: CashRegisterMovementMetricsPayload;
}

/** One aggregate in the breakdown, as the card renders it. */
export interface CashMovementTypeBreakdown {
  type: string;
  /** The label the API sent, used as-is. */
  label: string;
  direction: CashMovementDirection;
  origin: CashMovementOrigin;
  transactionCount: number;
  /** The API's own aggregate for this type, as a magnitude. */
  amount: number;
}

/** One movement row, as the card renders it. */
export interface CashMovementRow {
  id: number;
  /** Pre-formatted local timestamp, or `""` when the API sent none. */
  occurredAt: string;
  /** `cash_registers.id` — there is no name column to show. */
  registerId: number;
  type: string;
  label: string;
  direction: CashMovementDirection;
  origin: CashMovementOrigin;
  amount: number;
  /** Model basename of the referenced document, or `null`. */
  reference: string | null;
  note: string | null;
  /** The operator's name, or `null` when suppressed. */
  operator: string | null;
}

/** The cash register movement summary as the UI renders it. */
export interface CashRegisterMovementReport {
  period: ReportPeriod;
  /** `metrics.total_cash_in`. */
  totalCashIn: number;
  /** `metrics.total_cash_out`. */
  totalCashOut: number;
  /** `metrics.net_cash_movement` — the API's own arithmetic, not a client sum. */
  netCashMovement: number;
  /** `metrics.transaction_count` — every register row in the window. */
  transactionCount: number;
  /** `metrics.cash_movement_count` — the rows that actually moved cash. */
  cashMovementCount: number;
  /** `metrics.non_movement_count` — the `opening` / `closing` markers. */
  nonMovementCount: number;
  /** `metrics.by_type`, in the order the API ordered it. */
  byType: CashMovementTypeBreakdown[];
  /** `metrics.movements`, newest first. */
  movements: CashMovementRow[];
}

/** Everything `GET /reports/*` returns for the selected window, mapped once. */
export interface ReportsSnapshot {
  sales: SalesReport;
  purchases: PurchaseReport;
  expenses: ExpenseReport;
  profitLoss: ProfitLossReport;
  inventory: InventoryReport;
  dashboard: DashboardReport;
  /**
   * `GET /reports/customer-outstanding` — a **position** report, not a period one.
   *
   * It is loaded alongside the others for one practical reason: the screen already
   * refetches on every date change, so the outstanding register would otherwise be
   * the one card on `/reports` showing figures from a different load than the rest.
   * Its balances are unaffected by the window, so this costs nothing but keeps the
   * screen consistent.
   */
  customerOutstanding: CustomerOutstandingReport;
  /**
   * `GET /reports/supplier-outstanding` — also a **position** report, and for
   * the same practical reason as `customerOutstanding`: the screen refetches on
   * every date change, so the payables register would otherwise be the one card
   * showing figures from a different load than the rest. Its balances are
   * unaffected by the window, so this costs nothing but keeps the screen
   * consistent.
   */
  supplierOutstanding: SupplierOutstandingReport;
  /**
   * `GET /reports/cash-register-movements` — what actually moved through the
   * cash drawers inside the window.
   *
   * Unlike the two outstanding registers above this is a genuine **period**
   * report: the figures it shows belong to the selected dates, so it belongs in the
   * same refetch as the sales and expense cards rather than being window-independent.
   *
   * Its source of truth is `cash_register_transactions` alone — the sale, expense
   * and return tables are never read to re-derive a movement — so a card sale or a
   * bank-paid expense cannot appear here, because neither wrote a drawer row.
   */
  cashRegisterMovements: CashRegisterMovementReport;
}

/* ---------------------------------------------------------------------------
 * Known backend gaps
 * ------------------------------------------------------------------------- */

/**
 * The report breakdowns the Reports screen draws but the reports API cannot
 * answer, with the reason shown in place of each one.
 *
 * `App\Services\Report\ReportService::inventory()` counts low-stock products
 * without returning which ones, and no other route in `routes/api.php`
 * produces that list — so the card states the gap instead of showing a number
 * the server did not send.
 *
 * It is intentionally left unavailable here rather than approximated from
 * `/products`: that endpoint is a paginated document list, so paging it would
 * silently report a partial catalogue as if it were the whole one.
 *
 * **Top products is no longer in this list.** `ReportService::sales()` now
 * groups its own sales population down to the sale lines and returns
 * `metrics.top_products`, so the card plots the API's own figures and issues
 * no request of its own. Sales daily and monthly trends went the same way,
 * via `metrics.daily_series` and `metrics.monthly_series`. What remains here
 * is what the server still does not answer.
 */
export const REPORT_UNAVAILABLE_REASONS = {
  /** The item list inside the low-stock card. */
  lowStockItems:
    "The inventory report returns a low-stock count, but not the products behind it.",
} as const;
