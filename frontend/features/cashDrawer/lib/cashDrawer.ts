import type {
  CashTransactionDirection,
  CashTransactionType,
} from "@/types/cashDrawer";

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
  cash_in: "Cash in",
  cash_out: "Cash out",
  closing: "Closing count",
};

/** Display label of one transaction type. */
export function cashTransactionLabel(type: CashTransactionType): string {
  return CASH_TRANSACTION_LABELS[type];
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
  if (type === "expense" || type === "cash_out") {
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