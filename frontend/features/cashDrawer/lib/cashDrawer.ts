import type {
  CashRegisterTransaction,
  CashTransactionDirection,
  CashTransactionType,
} from "@/types/cashDrawer";

/** The two transaction types a person types in by hand. */
const MANUAL_CASH_TYPES: readonly CashTransactionType[] = ["cash_in", "cash_out"];

/**
 * Display-only helpers for the cash drawer screen.
 *
 * Everything here is presentation. The drawer deliberately computes **no**
 * money: `currentBalance`, the inflow/outflow totals and the closing variance
 * all come from `CashRegisterService` and arrive through
 * `lib/cashRegisterMapper`. These helpers only turn the API's stored values
 * into the words, signs and badges the tables show.
 */

/** Wording of each transaction type, used by the history table. */
export const CASH_TRANSACTION_LABELS: Readonly<
  Record<CashTransactionType, string>
> = {
  opening: "Opening float",
  sale: "Cash sale",
  expense: "Expense",
  refund: "Refund (sale return)",
  cash_in: "Cash in",
  cash_out: "Cash out",
  closing: "Closing count",
};

/** Display label of one transaction type. */
export function cashTransactionLabel(type: CashTransactionType): string {
  return CASH_TRANSACTION_LABELS[type];
}

/**
 * Is this a movement a person typed into the drawer by hand?
 *
 * The client-side mirror of the rule `CashRegisterService` enforces before it
 * will update or delete a row, and it is deliberately the *same* rule rather
 * than a type check alone: `referenceType` / `referenceId` are what mark a
 * movement as belonging to a sale, an expense or a payment, so a row carrying
 * one is automatic even if its type says `cash_in`.
 *
 * This decides whether the Edit and Delete actions are offered at all. It is
 * **not** the security boundary — the API re-checks the same condition and
 * refuses the row regardless of what the UI offered — but without it a cashier
 * would see a button that is guaranteed to fail, and a reference-linked row
 * would appear amendable when it is not.
 */
export function isManualCashMovement(
  transaction: CashRegisterTransaction,
): boolean {
  return (
    MANUAL_CASH_TYPES.includes(transaction.type) &&
    transaction.referenceType === null &&
    transaction.referenceId === null
  );
}

/**
 * How a transaction type moves the drawer.
 *
 * Mirrors `CashRegisterTransaction::INFLOW_TYPES` / `OUTFLOW_TYPES`. The
 * `opening` and `closing` rows are markers: the backend excludes them from both
 * sums, so they are reported as `neutral` rather than being given a sign.
 */
export function cashTransactionDirection(
  type: CashTransactionType,
): CashTransactionDirection {
  if (type === "sale" || type === "cash_in") {
    return "in";
  }
  // `refund` is grouped with the outflows for the same reason it is an outflow
  // in the backend: it is cash handed back to a customer on a sale return, so it
  // lowers the drawer exactly as an expense does.
  if (type === "expense" || type === "refund" || type === "cash_out") {
    return "out";
  }
  return "neutral";
}

/**
 * Short reference of the document a transaction was written for, e.g.
 * `Payment #42`.
 *
 * `reference_type` is a morph class name (`App\Models\Payment`), so only the
 * last segment is shown. Returns `null` for the rows the API writes itself
 * (opening/closing) and for manual cash in/out, which have no reference.
 */
export function cashTransactionReference(
  referenceType: string | null,
  referenceId: number | null,
): string | null {
  if (referenceType === null || referenceId === null) {
    return null;
  }
  const segments = referenceType.split("\\");
  const className = segments[segments.length - 1] ?? referenceType;
  return `${className} #${referenceId}`;
}