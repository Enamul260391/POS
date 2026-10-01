/**
 * Shop expense and expense category, mirroring `ExpenseResource` and
 * `ExpenseCategoryResource`.
 *
 * The Phase 11 mock modelled a richer expense than the database has. The
 * `expenses` table has **no `title` column, no `provider` column and no
 * eight-value `category` enum** — categories are real rows in
 * `expense_categories`, addressed by `expense_category_id`. Following the rule
 * the supplier and customer migrations already used, the invented fields were
 * removed rather than fed with placeholder values, and everything the API does
 * own is named exactly as the resource returns it.
 *
 * Money follows the project convention: Laravel casts the column to
 * `decimal:2` and serialises it as a **string** so no cent is lost to a float,
 * so `amount` is a `number` here only after the mapper has converted it with
 * `toMoney` (`@/lib/api/values`).
 *
 * Conventions used by every domain type in this folder:
 * - Field names are camelCase; the `snake_case` → `camelCase` conversion lives
 *   once in `features/expenses/lib/expenseMapper.ts`, never in a component.
 * - Values are never invented: a missing relation reads as `null`.
 */

/**
 * Payment methods `Expense::PAYMENT_METHODS` validates against.
 *
 * Narrower than the POS `PaymentMethod` union on purpose: an expense has no
 * `due` or `store_credit` settlement, so offering them would only produce a
 * `422`. `mobile_banking` has no column either — the API records wallet
 * settlements as `bank`, and the gateway name belongs in `reference`.
 */
export type ExpensePaymentMethod = "cash" | "card" | "bank" | "cheque";

/** Whether the category can be picked on an expense. */
export type ExpenseCategoryStatus = "active" | "inactive";

/**
 * Expense category — where the money went.
 *
 * A tenant-owned lookup row (`name`, `description`, `status`), not a fixed enum,
 * so the expense form and the list filter are both driven by whatever the
 * business has actually configured.
 */
export interface ExpenseCategory {
  /** Primary key; sent as `expense_category_id`. */
  id: number;
  /** Display name, e.g. "Shop rent". */
  name: string;
  /** Optional longer note about the category. */
  description: string | null;
  /** Inactive categories stay selectable for historical rows but are not offered. */
  status: ExpenseCategoryStatus;
  /** Number of expenses filed under this category, counted by the API. */
  expenseCount: number;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Raw `ExpenseCategoryResource` payload. */
export interface ExpenseCategoryPayload {
  id: number;
  name: string;
  description: string | null;
  status: string;
  /** Only present when the query counted it (`withCount('expenses')`). */
  expenses_count?: number;
  created_at: string;
  updated_at: string;
}

export interface Expense {
  /** Primary key. */
  id: number;
  /** Category this expense was filed under; `null` when filed uncategorised. */
  categoryId: number | null;
  /**
   * Name of the linked category, read from the embedded `category` resource.
   *
   * `null` both when the expense has no category and when the caller asked for
   * a payload that did not eager-load the relation, so the list must always
   * render a fallback rather than assume a name is there.
   */
  categoryName: string | null;
  /** Amount spent (BDT), converted from the API's decimal string. */
  amount: number;
  /**
   * The day the money was spent, exactly as stored: `YYYY-MM-DD`.
   *
   * The column is a `date`, not a `datetime` — the API has no time of day and
   * the UI must not invent one.
   */
  expenseDate: string;
  /**
   * The same day as an ISO instant anchored to **local** midnight.
   *
   * Derived in the mapper, because a bare `YYYY-MM-DD` is parsed by `Date` as
   * UTC and would therefore render as the previous day west of Greenwich. This
   * is the field date-window maths (the reports screen) compares against; use
   * {@link Expense.expenseDate} for anything the user reads.
   */
  spentAt: string;
  /** How the expense was paid. */
  paymentMethod: ExpensePaymentMethod;
  /**
   * Short human label for the expense, e.g. "Rent — September" or a receipt
   * number. Optional: the API stores `null` and never generates one, so the
   * client must not invent a sequence.
   */
  reference: string | null;
  /** Free-text note — receipt number, reason, details. */
  description: string | null;
  /** Display name of the staff member who filed it, from the `creator` relation. */
  recordedBy: string | null;
  /**
   * Id of the cash-register transaction this expense generated, or `null` when
   * it never touched a drawer (a non-cash expense, or a cash expense posted
   * while no register was open).
   */
  cashRegisterTransactionId: number | null;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Raw `ExpenseResource` payload. */
export interface ExpensePayload {
  id: number;
  expense_category_id: number | null;
  /** Decimal **string** — Laravel casts `amount` to `decimal:2` deliberately. */
  amount: string;
  /** `YYYY-MM-DD`; absent only if the row somehow has no date. */
  expense_date: string | null;
  payment_method: string;
  reference: string | null;
  description: string | null;
  created_by: number | null;
  /** Present only when the relation was eager-loaded. */
  category?: ExpenseCategoryPayload | null;
  creator?: { id: number; name: string } | null;
  /** Present only when the cash-register relation was loaded. */
  cash_register_transaction_id?: number | null;
  created_at: string;
  updated_at: string;
}
