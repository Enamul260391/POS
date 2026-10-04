import { apiGet } from "@/lib/api/request";
import type { ApiRequestConfig } from "@/lib/api/request";
import {
  toCashRegisterMovementReport,
  toCustomerOutstandingReport,
  toDashboardReport,
  toExpenseReport,
  toInventoryReport,
  toProfitLossReport,
  toPurchaseReport,
  toSalesReport,
  toSupplierOutstandingReport,
} from "@/features/reports/lib/reportMapper";
import type {
  CashRegisterMovementReport,
  CashRegisterMovementReportPayload,
  CustomerOutstandingReport,
  CustomerOutstandingReportPayload,
  DashboardReport,
  DashboardReportPayload,
  ExpenseReport,
  ExpenseReportPayload,
  InventoryReport,
  InventoryReportPayload,
  ProfitLossReport,
  ProfitLossReportPayload,
  PurchaseReport,
  PurchaseReportPayload,
  ReportsSnapshot,
  SalesReport,
  SalesReportPayload,
  SupplierOutstandingReport,
  SupplierOutstandingReportPayload,
} from "@/types/report";

/**
 * Report application service — the only module that talks to `/reports/*`.
 *
 * Every function maps onto a route that already exists in `routes/api.php`
 * (`ReportController@…`, six of them). Nothing is invented here, no duplicate
 * endpoint is added, and no report aggregate is computed on the client: the
 * service sends the requested window and hands the response to `reportMapper`,
 * which only converts wire format.
 *
 * What the backend owns, and the client therefore never sends or computes:
 *
 *   - the tenant. `business_id` is never part of a request; the service scopes
 *     to the callers business from the bearer token alone, so a client cannot
 *     read another tenants totals even by trying.
 *   - every aggregate. Discount, tax, paid and due are already netted and
 *     summed in SQL; the frontend never re-adds them to `grand_total`.
 *   - the cost of goods and profit, which belong to `profitLoss()`.
 *     `unit_cost`, and withholds the cost-derived figures (`null`) while any
 *     through rather than papered over with a client-side subtraction.
 *
 * Filtering is the servers: `ReportFilterRequest` validates the date window and
 * the optional dimensions, and rejects an id belonging to another business. The
 * screen itself only ever supplies the window.
 */

/** Query parameters accepted by every report route (`ReportFilterRequest`). */
export interface ReportFilters {
  /** Inclusive lower bound as `YYYY-MM-DD`; see the note on `toQuery`. */
  dateFrom?: string | null;
  /** Inclusive upper bound as `YYYY-MM-DD`. */
  dateTo?: string | null;
  /** Sales report only: restrict to one customer of this business. */
  customerId?: number | null;
  /** Purchases report only: restrict to one supplier of this business. */
  supplierId?: number | null;
  /** Expenses report only: restrict to one expense category of this business. */
  expenseCategoryId?: number | null;
  /** Sales report only: `cash` / `card` / `bank` / `cheque`. */
  paymentMethod?: string | null;
  /** Sales report only: a `Sale::STATUSES` value. */
  status?: string | null;
}

/**
 * Maps the frontend filter object onto the query string.
 *
 * The window travels as a **bare `YYYY-MM-DD` string**, never an ISO
 * timestamp. The API validates `date_format:Y-m-d`, and the columns it compares
 * against (`sale_date`, `purchase_date`, `expense_date`) are `date`, not
 * `datetime` — so a timestamp would fail validation outright, and formatting a
 * date as an instant would additionally risk shifting the day across timezones.
 * The browsers own calendar getters build the string, so the day the user picked
 * is the day the server filters on.
 *
 * `cleanParams` in `@/lib/api/request` then drops the empty entries, so an unset
 * filter is never sent rather than sent as an empty string.
 */
function toQuery(
  filters: ReportFilters | undefined,
): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }

  return {
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    customer_id: filters.customerId,
    supplier_id: filters.supplierId,
    expense_category_id: filters.expenseCategoryId,
    payment_method: filters.paymentMethod,
    status: filters.status,
  };
}

/** `GET /reports/sales` — the sales totals for the window. */
export async function getSalesReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<SalesReport> {
  const payload = await apiGet<SalesReportPayload>("/reports/sales", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return toSalesReport(payload);
}

/** `GET /reports/purchases` — the purchase totals for the window. */
export async function getPurchaseReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<PurchaseReport> {
  const payload = await apiGet<PurchaseReportPayload>("/reports/purchases", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return toPurchaseReport(payload);
}

/** `GET /reports/expenses` — expense totals, split by category. */
export async function getExpenseReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<ExpenseReport> {
  const payload = await apiGet<ExpenseReportPayload>("/reports/expenses", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return toExpenseReport(payload);
}

/**
 * `GET /reports/profit-loss` — the profit/loss answer for the window.
 *
 * Gross profit, net profit and the margin are the APIs own figures, computed
 * from the unit cost snapshotted on each sale line. They are withheld (`null`)
 * while any line in the window has no recorded cost, and that `null` is passed
 * through so the card shows the APIs explanation rather than a fabricated one.
 */
export async function getProfitLossReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<ProfitLossReport> {
  const payload = await apiGet<ProfitLossReportPayload>("/reports/profit-loss", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return toProfitLossReport(payload);
}

/**
 * `GET /reports/inventory` — the current stock snapshot.
 *
 * Takes no period: stock is a point-in-time reading derived from the ledger, so
 * the controller validates the date params and then ignores them.
 */
export async function getInventoryReport(
  config?: ApiRequestConfig,
): Promise<InventoryReport> {
  const payload = await apiGet<InventoryReportPayload>("/reports/inventory", {
    signal: config?.signal,
  });

  return toInventoryReport(payload);
}

/**
 * `GET /reports/dashboard` — todays headline figures.
 *
 * The endpoint is today-only by construction: `ReportService::dashboard()`
 * hard-codes todays window in the business timezone, so the screen sends no
 * period and presents the result as the "today" reference row.
 */
export async function getDashboardReport(
  config?: ApiRequestConfig,
): Promise<DashboardReport> {
  const payload = await apiGet<DashboardReportPayload>("/reports/dashboard", {
    signal: config?.signal,
  });

  return toDashboardReport(payload);
}

/**
 * Loads the whole `/reports` screen in one pass.
 *
 * The six reports are independent aggregates, so they are fetched concurrently
 * rather than in sequence — changing the period costs one round trips latency,
 * not six. They share one `AbortSignal`: a superseded load (the user picked
 * another period) cancels all six together, so a slow response can never land on
 * top of a newer set of figures.
 *
 * `Promise.all` is deliberate. The reports share a scope and a failure mode (a
 * bad token, a suspended account, an unreachable server), so a partial result
 * would not be a meaningful report — it would be a screen where some cards
 * silently show a period the user never asked for. One rejected request
 * *
 * `GET /reports/customer-outstanding` — who owes whom, right now.
 *
 * A **position** report, unlike every other route this module calls, and that
 * difference is the whole reason it has an endpoint of its own rather than being
 * another field on `/reports/sales`:
 *
 *  - The balance is `SUM(customer_ledger_entries.amount)` per customer over the
 *    ledger's *whole history*. The window is deliberately **not** applied to it,
 *    so the figures cannot change when the user moves the date filter. Only the
 *    separately named `period_movement` is windowed.
 *  - `sales.due_amount` is never used. A later payment revises that invoice
 *    column, a later return never does, so it is a per-invoice historical figure
 *    rather than a balance.
 *
 * `ReportFilterRequest` is reused unchanged, so `customerId` is validated against
 * this business exactly as it is everywhere else: a foreign id is a 422 on the
 * established rule, not a new authorization path. The screen sends only the window.
 */
export async function getCustomerOutstandingReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<CustomerOutstandingReport> {
  const payload = await apiGet<CustomerOutstandingReportPayload>(
    "/reports/customer-outstanding",
    {
      params: toQuery(filters),
      signal: config?.signal,
    },
  );

  return toCustomerOutstandingReport(payload);
}

/**
 * `GET /reports/supplier-outstanding` — who the business owes, right now.
 *
 * The payables mirror of the report above, and a **position** report for the same
 * reason:
 *
 *  - The balance is `suppliers.opening_balance + SUM(purchases.due_amount)` per
 *    supplier over the whole history. The window is deliberately **not** applied
 *    to it, so the figures cannot change when the user moves the date filter.
 *    Only the separately named `period_movement` is windowed.
 *  - Unlike the customer side there is no ledger to read, and none is invented:
 *    the purchase flow rewrites `due_amount` on every write that moves it, and
 *    the schema has no supplier return or credit note that could leave it stale.
 *
 * `ReportFilterRequest` is reused unchanged, so `supplierId` is validated
 * against this business exactly as it is everywhere else: a foreign id is a 422
 * on the established rule, not a new authorization path. The screen sends only
 * the window.
 */
export async function getSupplierOutstandingReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<SupplierOutstandingReport> {
  const payload = await apiGet<SupplierOutstandingReportPayload>(
    "/reports/supplier-outstanding",
    {
      params: toQuery(filters),
      signal: config?.signal,
    },
  );

  return toSupplierOutstandingReport(payload);
}

/**
 * `GET /reports/cash-register-movements` — what actually moved through the cash
 * drawers inside the window.
 *
 * Unlike `customerOutstandingReport` and `supplierOutstandingReport`, this is a
 * genuine **period** report: the figures belong to the requested dates, so the
 * screen refetches it with everything else when the filter changes.
 *
 * The source of truth is `cash_register_transactions` alone. The sale, expense,
 * payment and return tables are never consulted to re-derive a movement, because a
 * card or bank transaction posts no drawer row at all — so a report built from them
 * would be a *receipts* report wearing the wrong name.
 *
 * `ReportFilterRequest` also accepts `cashRegisterId` and `transactionType`, both
 * validated server-side against this business's registers and the canonical
 * `CashRegisterTransaction::TYPES`. The `/reports` screen sends neither, since its
 * only filter is the date window.
 */
export async function getCashRegisterMovementReport(
  filters?: ReportFilters,
  config?: ApiRequestConfig,
): Promise<CashRegisterMovementReport> {
  const payload = await apiGet<CashRegisterMovementReportPayload>(
    "/reports/cash-register-movements",
    {
      params: toQuery(filters),
      signal: config?.signal,
    },
  );

  return toCashRegisterMovementReport(payload);
}

/**
 * Loads the whole `/reports` screen in one pass.
 *
 * The reports are independent aggregates, so they are fetched concurrently rather
 * than in sequence — changing the period costs one round trip's latency, not one
 * per report. They share one `AbortSignal`: a superseded load (the user picked
 * another period) cancels them all together, so a slow response can never land on
 * top of a newer set of figures.
 *
 * `Promise.all` is deliberate. The reports share a scope and a failure mode (a
 * bad token, a suspended account, an unreachable server), so a partial result
 * would not be a meaningful report — it would be a screen where some cards
 * silently show a period the user never asked for. One rejected request
 * therefore surfaces as one error on the screen, with a retry.
 *
 * `customerOutstanding` is fetched here too, even though its balances ignore the
 * window: the screen refetches everything else on every date change, and the
 * receivables register is not a card that should be left showing figures from an
 * older load than the card beside it.
 */
export async function loadReportsSnapshot(
  filters: ReportFilters | undefined,
  config?: ApiRequestConfig,
): Promise<ReportsSnapshot> {
  const signal = config?.signal;

  const [
    sales,
    purchases,
    expenses,
    profitLoss,
    inventory,
    dashboard,
    customerOutstanding,
    supplierOutstanding,
    cashRegisterMovements,
  ] = await Promise.all([
    getSalesReport(filters, { signal }),
    getPurchaseReport(filters, { signal }),
    getExpenseReport(filters, { signal }),
    getProfitLossReport(filters, { signal }),
    getInventoryReport({ signal }),
    getDashboardReport({ signal }),
    getCustomerOutstandingReport(filters, { signal }),
    getSupplierOutstandingReport(filters, { signal }),
    getCashRegisterMovementReport(filters, { signal }),
  ]);

  return {
    sales,
    purchases,
    expenses,
    profitLoss,
    inventory,
    dashboard,
    customerOutstanding,
    supplierOutstanding,
    cashRegisterMovements,
  };
}
