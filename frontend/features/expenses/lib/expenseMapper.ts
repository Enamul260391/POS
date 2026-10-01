import { toMoney, toText } from "@/lib/api/values";
import type {
  Expense,
  ExpenseCategory,
  ExpenseCategoryPayload,
  ExpenseCategoryStatus,
  ExpensePayload,
  ExpensePaymentMethod,
} from "@/types/expense";

/**
 * `ExpenseResource` → `Expense`, and `ExpenseCategoryResource` →
 * `ExpenseCategory`.
 *
 * Every snake_case → camelCase conversion for the expenses module happens here,
 * once, so no component ever reads a raw payload and the differences between
 * the API and the domain types stay in a single file.
 *
 * Two conversions are worth calling out:
 *
 *   - `amount` arrives as a decimal **string** (`"25000.00"`) because Laravel
 *     casts the column to `decimal:2`; it is converted with the shared
 *     `toMoney` rather than parsed inline.
 *   - `expense_date` is a `date` column serialised as `YYYY-MM-DD`. `Date`
 *     parses that bare string as **UTC**, so west of Greenwich it would render
 *     as the previous day. It is therefore read as the plain calendar date it
 *     is, and a second, local-midnight ISO field is derived for the
 *     date-window comparisons that expect a real instant.
 */

/** Narrows `Expense::PAYMENT_METHODS` onto the UI union. */
export function toExpensePaymentMethod(value: string): ExpensePaymentMethod {
  switch (value) {
    case "card":
      return "card";
    case "bank":
      return "bank";
    case "cheque":
      return "cheque";
    case "cash":
    default:
      // Cash is the only method that reaches the cash drawer, so an
      // unrecognised value must never be read as a non-cash settlement.
      return "cash";
  }
}

/** Narrows `ExpenseCategory::STATUSES`. */
export function toExpenseCategoryStatus(value: string): ExpenseCategoryStatus {
  return value === "active" ? "active" : "inactive";
}

/**
 * `YYYY-MM-DD` read as a calendar day, not as an instant.
 *
 * Returns `null` for a missing or malformed value so the caller can decide how
 * to render the gap rather than printing `Invalid Date`.
 */
function toCalendarDate(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return /^\d{4}-\d{2}-\d{2}$/.test(trimmed) ? trimmed : null;
}

/** The same calendar day as an ISO instant anchored to local midnight. */
function toLocalMidnightIso(date: string): string {
  // `YYYY-MM-DDT00:00:00` (no `Z`) is parsed as local time by design, which is
  // what makes the resulting instant land on that same day for the user.
  const local = new Date(`${date}T00:00:00`);
  return Number.isNaN(local.getTime()) ? "" : local.toISOString();
}

export function toExpense(payload: ExpensePayload): Expense {
  const expenseDate = toCalendarDate(payload.expense_date);

  return {
    id: payload.id,
    categoryId: payload.expense_category_id ?? null,
    // The relation is eager-loaded by the list/show queries, but the resource
    // only emits it when it is present, so it stays optional all the way here.
    categoryName: toText(payload.category?.name),
    amount: toMoney(payload.amount),
    expenseDate: expenseDate ?? "",
    spentAt: expenseDate === null ? "" : toLocalMidnightIso(expenseDate),
    paymentMethod: toExpensePaymentMethod(payload.payment_method),
    reference: toText(payload.reference),
    description: toText(payload.description),
    recordedBy: toText(payload.creator?.name),
    cashRegisterTransactionId: payload.cash_register_transaction_id ?? null,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of expenses. */
export function toExpenses(payloads: readonly ExpensePayload[]): Expense[] {
  return payloads.map(toExpense);
}

export function toExpenseCategory(
  payload: ExpenseCategoryPayload,
): ExpenseCategory {
  return {
    id: payload.id,
    name: payload.name,
    description: toText(payload.description),
    status: toExpenseCategoryStatus(payload.status),
    // `whenCounted` omits the key entirely when the query did not count it.
    expenseCount: payload.expenses_count ?? 0,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a page of expense categories. */
export function toExpenseCategories(
  payloads: readonly ExpenseCategoryPayload[],
): ExpenseCategory[] {
  return payloads.map(toExpenseCategory);
}