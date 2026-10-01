/**
 * Cash register / drawer (API-backed).
 *
 * The type mirrors `App\Http\Resources\CashRegisterResource`,
 * `CashRegisterTransactionResource` and the `summary()` payload of
 * `App\Services\CashRegister\CashRegisterService` exactly. Laravel owns the
 * balances: `current_balance`, `transaction_summary` and the closing figures
 * (`closing_balance` / `expected_balance` / `difference`) are all server
 * aggregates, so nothing here is derived from the transaction list.
 *
 * Ownership and tenancy are never modelled on the client: `business_id`,
 * `opened_by` and `closed_by` are resolved from the bearer token by the API,
 * and the screen only ever reads the authenticated user's own register.
 */

/** Lifecycle state of a register shift (`CashRegister::STATUSES`). */
export type CashRegisterStatus = "open" | "closed";

/** Row type of `cash_register_transactions` (`CashRegisterTransaction::TYPES`). */
export type CashTransactionType =
  | "opening"
  | "sale"
  | "expense"
  | "cash_in"
  | "cash_out"
  | "closing";

/**
 * Which way a transaction moves the drawer, derived from its type because
 * `amount` is always stored positive:
 * - `in`       `sale`, `cash_in`
 * - `out`      `expense`, `cash_out`
 * - `neutral`  the `opening` / `closing` markers, which are excluded from the
 *              flow sums by the backend
 */
export type CashTransactionDirection = "in" | "out" | "neutral";

/** Display of the user a transaction was recorded by. */
export interface CashTransactionUser {
  id: number;
  name: string;
}

export interface CashRegisterTransaction {
  /** Primary key. */
  id: number;
  /** Register the transaction belongs to. */
  cashRegisterId: number;
  /** What happened to the cash. */
  type: CashTransactionType;
  /** Stored amount (BDT), always positive. */
  amount: number;
  /** How this row affects the drawer balance. */
  direction: CashTransactionDirection;
  /** Morph class of the document this row was written for, e.g. `Payment`. */
  referenceType: string | null;
  /** Id inside the referenced document. */
  referenceId: number | null;
  /** Free text note written by the API or the cashier. */
  note: string | null;
  /** Id of the user who recorded it. */
  createdBy: number | null;
  /** Name of that user, when the API eager-loaded it. */
  recordedBy: string | null;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Inflow / outflow aggregates the API computes for one register. */
export interface CashRegisterTotals {
  totalIn: number;
  totalOut: number;
  currentBalance: number;
}

export interface CashRegister {
  /** Primary key. */
  id: number;
  /** `open` while the shift runs, `closed` afterwards. */
  status: CashRegisterStatus;
  /** Float counted when the register opened (BDT). */
  openingBalance: number;
  /** Id of the user who opened it. */
  openedBy: number | null;
  /** Name of that user, when the API eager-loaded it. */
  openedByName: string | null;
  /** ISO 8601 timestamp of when the register opened. */
  openedAt: string | null;
  /** Id of the user who closed it, `null` while open. */
  closedBy: number | null;
  /** Name of that user, when the API eager-loaded it. */
  closedByName: string | null;
  /** ISO 8601 timestamp of when the register closed, `null` while open. */
  closedAt: string | null;
  /** Counted cash at close, `null` while open. */
  closingBalance: number | null;
  /** Cash the API expected at close, `null` while open. */
  expectedBalance: number | null;
  /** `closing_balance − expected_balance` as persisted by the API. */
  difference: number | null;
  /** Note recorded at open or close. */
  notes: string | null;
  /** Live balance the API derived from the register's own transactions. */
  currentBalance: number;
  /** Inflow / outflow totals the API derived alongside the balance. */
  totals: CashRegisterTotals;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** `data` of `GET /cash-registers/{id}/summary`. */
export interface CashRegisterSummary {
  cashRegisterId: number;
  openingBalance: number;
  totalIn: number;
  totalOut: number;
  transactionCount: number;
  currentBalance: number;
}

/**
 * Raw `CashRegisterResource` payload.
 *
 * `current_balance` and `transaction_summary` are emitted through Laravel's
 * `when(isset(...))`, so both are optional here and default to zero/empty.
 */
export interface CashRegisterPayload {
  id: number;
  opened_by: number | null;
  opened_at: string | null;
  opening_balance: string;
  closed_by: number | null;
  closed_at: string | null;
  closing_balance: string | null;
  expected_balance: string | null;
  difference: string | null;
  status: string;
  notes: string | null;
  current_balance?: string;
  transaction_summary?: {
    total_in: string;
    total_out: string;
    current_balance: string;
  };
  opened_by_user?: CashTransactionUser | null;
  closed_by_user?: CashTransactionUser | null;
  created_at: string;
  updated_at: string;
}

/** Raw `CashRegisterTransactionResource` payload. */
export interface CashRegisterTransactionPayload {
  id: number;
  cash_register_id: number;
  type: string;
  amount: string;
  reference_type: string | null;
  reference_id: number | null;
  note: string | null;
  created_by: number | null;
  creator?: CashTransactionUser | null;
  created_at: string;
  updated_at: string;
}

/** Raw summary payload of `GET /cash-registers/{id}/summary`. */
export interface CashRegisterSummaryPayload {
  cash_register_id: number;
  opening_balance: string;
  total_in: string;
  total_out: string;
  transaction_count: number;
  current_balance: string;
}
