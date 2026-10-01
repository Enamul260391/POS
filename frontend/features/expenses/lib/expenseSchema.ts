import { z } from "zod";

import { todayDateString } from "@/lib/api/values";
import type { Expense } from "@/types/expense";

/**
 * Form contract of the Add/Edit expense dialog.
 *
 * One schema serves validation and typing, and it is written against what
 * `StoreExpenseRequest` / `UpdateExpenseRequest` actually accept â€” nothing
 * more. The Phase 11 version carried a `title` and a `provider` field, but the
 * `expenses` table has neither column, so they were removed rather than being
 * posted into a payload the API would drop. `reference` is the short label the
 * API does store, so it took the title's place.
 *
 * The maxima mirror the backend rules exactly, so an obviously over-long value
 * is caught before a round trip:
 *   - `reference`  `string|max:100`
 *   - `description` `string|max:2000`
 *   - `amount`       `numeric|gt:0|decimal:0,2`
 *   - `expense_date` `date_format:Y-m-d`
 *
 * The client validates the *shape*; the API still decides everything else. A
 * `422` is mapped back onto these inputs by `applyValidationErrors`, and
 * `EXPENSE_FORM_FIELDS` is the list of names it may address.
 */

export const EXPENSE_FORM_FIELDS = [
  "reference",
  "categoryId",
  "amount",
  "expenseDate",
  "paymentMethod",
  "description",
] as const;

export const expenseSchema = z.object({
  /** Optional: the API stores `null` and never generates a reference. */
  reference: z.string().trim().max(100, "Reference is too long"),
  /** `null` files the expense uncategorised, which the API allows. */
  categoryId: z
    .number({ invalid_type_error: "Select a category" })
    .int()
    .positive()
    .nullable(),
  amount: z
    .number({ invalid_type_error: "Required" })
    .positive("Must be more than 0")
    // Mirrors the API's `decimal:0,2` rule so an over-precise amount is caught
    // before the round trip. The tolerance is required: `1.005 * 100` is
    // `100.49999999999999` in binary floating point, so an exact comparison
    // would reject values the user never typed.
    .refine(
      (value) => Math.abs(value * 100 - Math.round(value * 100)) < 1e-9,
      "Use at most 2 decimal places",
    ),
  expenseDate: z
    .string()
    .trim()
    .min(1, "Date is required")
    .regex(/^\d{4}-\d{2}-\d{2}$/, "Enter a valid date")
    .refine((value) => !Number.isNaN(new Date(value).getTime()), "Enter a valid date"),
  paymentMethod: z.enum(["cash", "card", "bank", "cheque"], {
    invalid_type_error: "Select a payment method",
  }),
  description: z.string().trim().max(2000, "Description is too long"),
});

export type ExpenseFormValues = z.infer<typeof expenseSchema>;

/**
 * Blank state for "Add expense".
 *
 * Cash is the common counter case, the amount starts as `NaN` (which the number
 * input renders empty, so a forgotten amount fails as "Required" instead of
 * silently saving à§³0), and the date defaults to today in the browser's own
 * time zone â€” matching what `todayDateString` sends elsewhere.
 */
export const EMPTY_EXPENSE_VALUES: ExpenseFormValues = {
  reference: "",
  categoryId: null,
  amount: Number.NaN,
  expenseDate: todayDateString(),
  paymentMethod: "cash",
  description: "",
};

/**
 * Maps an existing expense into form state (edit mode).
 *
 * `expenseDate` is copied verbatim: the mapper already guarantees it is either
 * a valid `YYYY-MM-DD` or an empty string, and this is exactly the value the
 * `<input type="date">` field and the API both expect â€” no timezone
 * round-tripping, which is what would shift the day.
 */
export function toExpenseFormValues(expense: Expense): ExpenseFormValues {
  return {
    reference: expense.reference ?? "",
    categoryId: expense.categoryId,
    amount: expense.amount,
    expenseDate: expense.expenseDate,
    paymentMethod: expense.paymentMethod,
    description: expense.description ?? "",
  };
}

