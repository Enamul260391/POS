import type { Expense, ExpensePaymentMethod } from "@/types/expense";

/**
 * Pure helpers for the expenses screens and the expense service.
 *
 * These used to own the list rules and the headline math, but the mock's
 * in-browser `filterExpenses` pass is gone for good: `GET /expenses` now takes
 * the search term and the category as query parameters and filters in SQL, so
 * the rows on screen are already exactly what matched. Re-filtering the current
 * page in memory would only disagree with the server and hide rows it
 * paginated in â€” the same decision the suppliers screen made.
 *
 * What is left here is display-only: wording, rounding and the summary line.
 * Money is rounded to two decimals, matching the shared `toMoney` conversion
 * the mapper already applied.
 */

/** Wording of each payment method, in the API's own vocabulary. */
export const EXPENSE_PAYMENT_METHOD_LABELS: Readonly<
  Record<ExpensePaymentMethod, string>
> = {
  cash: "Cash",
  card: "Card",
  bank: "Bank transfer",
  cheque: "Cheque",
};

/** Payment method options for the expense form. */
export const EXPENSE_PAYMENT_METHOD_OPTIONS: readonly {
  value: ExpensePaymentMethod;
  label: string;
}[] = [
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "bank", label: "Bank transfer" },
  { value: "cheque", label: "Cheque" },
];

/** Rounds to two decimals; non-finite input becomes `0`. */
export function roundMoney(amount: number): number {
  return Math.round((Number.isFinite(amount) ? amount : 0) * 100) / 100;
}

/**
 * Display label of one expense payment.
 *
 * The stored method is authoritative â€” there is no provider column on the
 * expense â€” so this never invents a gateway name the API did not record.
 */
export function expensePaymentLabel(expense: Expense): string {
  return EXPENSE_PAYMENT_METHOD_LABELS[expense.paymentMethod];
}

/**
 * Display label of the category.
 *
 * An uncategorised expense is a real state the API allows (`category_id` is
 * nullable), so it gets an explicit label rather than an empty cell.
 */
export function expenseCategoryLabel(expense: Expense): string {
  return expense.categoryName ?? "Uncategorised";
}

/**
 * The label a row is addressed by in aria labels and confirmations.
 *
 * The API stores `reference` as optional and never generates one, so the id is
 * the only guaranteed identifier.
 */
export function expenseLabel(expense: Expense): string {
  return expense.reference ?? `Expense #${expense.id}`;
}

/** Headline numbers above the expense table. */
export interface ExpenseSummary {
  /** Number of visible expenses. */
  count: number;
  /** Sum of visible amounts. */
  total: number;
  /** Sum of visible cash-paid amounts (drawer-relevant spend). */
  cashTotal: number;
}

/** Sums the visible expenses; pure so filters and reports agree. */
export function summariseExpenses(
  expenses: readonly Expense[],
): ExpenseSummary {
  let total = 0;
  let cashTotal = 0;
  for (const expense of expenses) {
    total = roundMoney(total + expense.amount);
    if (expense.paymentMethod === "cash") {
      cashTotal = roundMoney(cashTotal + expense.amount);
    }
  }
  return { count: expenses.length, total, cashTotal };
}
