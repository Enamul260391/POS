import { apiDelete, apiGet, apiList, apiPatch, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type {
  Expense,
  ExpenseCategory,
  ExpenseCategoryPayload,
  ExpensePayload,
  ExpensePaymentMethod,
} from "@/types/expense";

import {
  toExpense,
  toExpenseCategories,
  toExpenseCategory,
  toExpenses,
} from "../lib/expenseMapper";

/**
 * Expense application service — the only module that talks to `/expenses` and
 * `/expense-categories`.
 *
 * Every function maps onto a route that already exists
 * (`Route::apiResource('expenses')` and
 * `Route::apiResource('expense-categories')` in `routes/api.php`); nothing is
 * invented and no duplicate endpoint is added.
 *
 * What the backend owns, and the client therefore never sends or computes:
 *
 *   - the expense's tenant, `created_by` and its audit timestamps;
 *   - the cash-drawer pairing. A cash expense posts its register movement
 *     inside the same transaction that writes the expense, so this service
 *     never touches the drawer;
 *   - money formatting. `amount` is sent as a number, stored through
 *     `Money::format`, and read back as a decimal string by the mapper.
 *
 * No request carries `business_id`: Laravel derives the tenant from the bearer
 * token, and every id sent (the expense category) is validated against it.
 */

/** Query parameters accepted by `GET /expenses` (`IndexExpenseRequest`). */
export interface ExpenseFilters {
  /** Free text over `reference` and `description`. */
  search?: string;
  /** Restrict to one expense category. */
  categoryId?: number | null;
  /** Restrict to one payment method, in the API's own vocabulary. */
  paymentMethod?: ExpensePaymentMethod;
  /** Inclusive lower bound, `YYYY-MM-DD`. */
  dateFrom?: string;
  /** Inclusive upper bound, `YYYY-MM-DD`. */
  dateTo?: string;
  page?: number;
  perPage?: number;
  sort?:
    | "expense_date"
    | "amount"
    | "payment_method"
    | "reference"
    | "created_at"
    | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /expenses` (`StoreExpenseRequest`). */
export interface ExpenseInput {
  /** `null` files the expense uncategorised; the API allows it. */
  categoryId?: number | null;
  amount: number;
  /** `YYYY-MM-DD`, as `date_format:Y-m-d` requires. */
  expenseDate: string;
  paymentMethod?: ExpensePaymentMethod;
  reference?: string | null;
  description?: string | null;
}

/** Body of `PUT|PATCH /expenses/{id}` (`UpdateExpenseRequest`). */
export type ExpenseUpdateInput = Partial<ExpenseInput>;

/** Query parameters accepted by `GET /expense-categories`. */
export interface ExpenseCategoryFilters {
  search?: string;
  status?: "active" | "inactive";
  page?: number;
  perPage?: number;
  sort?: "name" | "status" | "created_at" | "id";
  direction?: "asc" | "desc";
}

/** Body of `POST /expense-categories` (`StoreExpenseCategoryRequest`). */
export interface ExpenseCategoryInput {
  name: string;
  description?: string | null;
  status?: "active" | "inactive";
}

/** Body of `PUT|PATCH /expense-categories/{id}`. */
export type ExpenseCategoryUpdateInput = Partial<ExpenseCategoryInput>;

/** Maps the frontend filter object onto the query string. */
function toQuery(filters: ExpenseFilters | undefined): ApiRequestConfig["params"] {
  if (filters === undefined) {
    return undefined;
  }
  return {
    search: filters.search,
    expense_category_id: filters.categoryId ?? undefined,
    payment_method: filters.paymentMethod,
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    page: filters.page,
    per_page: filters.perPage,
    sort: filters.sort,
    direction: filters.direction,
  };
}

/**
 * Builds the request body for create and update from one place, so the two can
 * never drift apart.
 *
 * Only fields the caller actually supplied are sent. That matters for update:
 * `UpdateExpenseRequest` treats every present key as "change this", and the
 * service layer refuses to touch the financial fields of an expense that has
 * already moved a cash register — so a partial update must not re-send `amount`
 * by accident and be rejected.
 */
function toExpenseBody(
  input: ExpenseInput | ExpenseUpdateInput,
): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  if (input.categoryId !== undefined) {
    body.expense_category_id = input.categoryId;
  }
  if (input.amount !== undefined) {
    body.amount = input.amount;
  }
  if (input.expenseDate !== undefined) {
    body.expense_date = input.expenseDate;
  }
  if (input.paymentMethod !== undefined) {
    body.payment_method = input.paymentMethod;
  }
  if (input.reference !== undefined) {
    body.reference = input.reference;
  }
  if (input.description !== undefined) {
    body.description = input.description;
  }
  return body;
}

/**
 * `GET /expenses` — one page, filtered and sorted by the API.
 *
 * Filtering happens in SQL, so the rows on screen are exactly the rows the
 * server matched; the browser never re-filters a page and would only disagree
 * with the server and silently hide rows the backend paginated in.
 */
export async function listExpenses(
  filters?: ExpenseFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<Expense>> {
  const data = await apiList<ExpensePayload>("/expenses", {
    params: toQuery(filters),
    signal: config?.signal,
  });

  return { items: toExpenses(data.items), pagination: data.pagination };
}

/** `GET /expenses/{id}` — one expense, with its relations eager-loaded. */
export async function getExpense(
  id: number,
  config?: ApiRequestConfig,
): Promise<Expense> {
  const payload = await apiGet<ExpensePayload>(`/expenses/${id}`, {
    signal: config?.signal,
  });
  return toExpense(payload);
}

/**
 * `POST /expenses` — records an expense.
 *
 * A cash expense also writes its cash-register movement inside the same server
 * transaction, which is why this call can still be rejected with a business
 * error even though the payload validated.
 */
export async function createExpense(input: ExpenseInput): Promise<Expense> {
  const payload = await apiPost<ExpensePayload>(
    "/expenses",
    toExpenseBody(input),
  );
  return toExpense(payload);
}

/**
 * `PUT|PATCH /expenses/{id}` — updates the supplied fields only.
 *
 * The response is the authoritative row. Financial fields of an expense that
 * has already affected a cash register are refused by the service layer with a
 * business error, which the shared error handling surfaces verbatim.
 */
export async function updateExpense(
  id: number,
  input: ExpenseUpdateInput,
): Promise<Expense> {
  const payload = await apiPatch<ExpensePayload>(
    `/expenses/${id}`,
    toExpenseBody(input),
  );
  return toExpense(payload);
}

/**
 * `DELETE /expenses/{id}` — removes an expense.
 *
 * Refused with a business error when the expense has affected a cash register;
 * that message is surfaced verbatim rather than being swallowed.
 */
export async function deleteExpense(id: number): Promise<void> {
  await apiDelete<null>(`/expenses/${id}`);
}


/** `GET /expense-categories` — one page, filtered and sorted by the API. */
export async function listExpenseCategories(
  filters?: ExpenseCategoryFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<ExpenseCategory>> {
  const params: ApiRequestConfig["params"] =
    filters === undefined
      ? undefined
      : {
          search: filters.search,
          status: filters.status,
          page: filters.page,
          per_page: filters.perPage,
          sort: filters.sort,
          direction: filters.direction,
        };
  const data = await apiList<ExpenseCategoryPayload>("/expense-categories", {
    params,
    signal: config?.signal,
  });

  return { items: toExpenseCategories(data.items), pagination: data.pagination };
}

/**
 * `GET /expense-categories` without pagination, for the closed set the expense
 * form and the list filter render.
 *
 * Uses the maximum page size the API allows rather than fetching "everything":
 * the backend caps a page at 100, so a business with more categories than that
 * still needs the paged path.
 */
export async function listAllExpenseCategories(
  config?: ApiRequestConfig,
): Promise<ExpenseCategory[]> {
  const data = await apiList<ExpenseCategoryPayload>("/expense-categories", {
    params: { per_page: 100, sort: "name", direction: "asc" },
    signal: config?.signal,
  });

  return toExpenseCategories(data.items);
}

/** `GET /expense-categories/{id}` — one category. */
export async function getExpenseCategory(
  id: number,
  config?: ApiRequestConfig,
): Promise<ExpenseCategory> {
  const payload = await apiGet<ExpenseCategoryPayload>(
    `/expense-categories/${id}`,
    { signal: config?.signal },
  );
  return toExpenseCategory(payload);
}

/** `POST /expense-categories` — creates a category for the caller's business. */
export async function createExpenseCategory(
  input: ExpenseCategoryInput,
): Promise<ExpenseCategory> {
  const payload = await apiPost<ExpenseCategoryPayload>("/expense-categories", {
    name: input.name,
    description: input.description ?? null,
    status: input.status ?? "active",
  });
  return toExpenseCategory(payload);
}

/** `PUT|PATCH /expense-categories/{id}` — partial update of supplied fields. */
export async function updateExpenseCategory(
  id: number,
  input: ExpenseCategoryUpdateInput,
): Promise<ExpenseCategory> {
  const payload = await apiPatch<ExpenseCategoryPayload>(
    `/expense-categories/${id}`,
    {
      ...(input.name === undefined ? {} : { name: input.name }),
      ...(input.description === undefined
        ? {}
        : { description: input.description }),
      ...(input.status === undefined ? {} : { status: input.status }),
    },
  );
  return toExpenseCategory(payload);
}

/**
 * `DELETE /expense-categories/{id}` — soft-deletes a category.
 *
 * Refused with a business error while any expense still points at it, so the
 * history can never be silently orphaned.
 */
export async function deleteExpenseCategory(id: number): Promise<void> {
  await apiDelete<null>(`/expense-categories/${id}`);
}

