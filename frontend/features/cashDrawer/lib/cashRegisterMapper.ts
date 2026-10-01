/**
 * `CashRegisterResource` → `CashRegister`, and the summary payload →
 * `CashRegisterSummary`.
 *
 * Every money figure the drawer shows is a **server** number. The API casts
 * the money columns to `decimal:2` and serialises them as strings, so they are
 * converted once here through `toMoney`; the frontend never re-derives a
 * balance by walking the transaction list (the list is paginated, so such a sum
 * would be wrong anyway).
 *
 * `current_balance` and `transaction_summary` are attached by
 * `CashRegisterService::present()` through Laravel's `when(isset(...))`, so they
 * can legitimately be absent. When they are, `currentBalance` falls back to the
 * register's own `opening_balance` and the totals to zero — the honest reading
 * of "a register the API presented without an aggregate".
 */
import { toMoney, toText } from "@/lib/api/values";
import type {
  CashRegister,
  CashRegisterPayload,
  CashRegisterStatus,
  CashRegisterSummary,
  CashRegisterSummaryPayload,
  CashRegisterTransaction,
  CashRegisterTransactionPayload,
  CashTransactionType,
} from "@/types/cashDrawer";

import { cashTransactionDirection } from "./cashDrawer";

/** Narrows `CashRegister::STATUSES`; anything unknown reads as `closed`. */
export function toCashRegisterStatus(value: string): CashRegisterStatus {
  return value === "open" ? "open" : "closed";
}

/**
 * Narrows `CashRegisterTransaction::TYPES`.
 *
 * An unrecognised type must still render, so it falls back to `cash_in` — the
 * only manual type — rather than being dropped from the history.
 */
export function toCashTransactionType(value: string): CashTransactionType {
  switch (value) {
    case "opening":
      return "opening";
    case "sale":
      return "sale";
    case "expense":
      return "expense";
    case "cash_out":
      return "cash_out";
    case "closing":
      return "closing";
    default:
      return "cash_in";
  }
}

/** `CashRegisterResource` → `CashRegister`. */
export function toCashRegister(payload: CashRegisterPayload): CashRegister {
  const totals = payload.transaction_summary;

  return {
    id: payload.id,
    status: toCashRegisterStatus(payload.status),
    openingBalance: toMoney(payload.opening_balance),
    openedBy: payload.opened_by,
    openedByName: toText(payload.opened_by_user?.name),
    openedAt: payload.opened_at,
    closedBy: payload.closed_by,
    closedByName: toText(payload.closed_by_user?.name),
    closedAt: payload.closed_at,
    // A register that is still open has never been counted, so the closing
    // figures stay `null` rather than being reported as a zero balance.
    closingBalance:
      payload.closing_balance === null ? null : toMoney(payload.closing_balance),
    expectedBalance:
      payload.expected_balance === null
        ? null
        : toMoney(payload.expected_balance),
    difference: payload.difference === null ? null : toMoney(payload.difference),
    notes: toText(payload.notes),
    currentBalance:
      payload.current_balance === undefined
        ? toMoney(payload.opening_balance)
        : toMoney(payload.current_balance),
    totals: {
      totalIn: toMoney(totals?.total_in),
      totalOut: toMoney(totals?.total_out),
      currentBalance: toMoney(totals?.current_balance),
    },
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/**
 * `CashRegisterTransactionResource` → `CashRegisterTransaction`.
 *
 * `amount` is stored positive, so the sign the history table renders comes from
 * `direction`, which is derived from the row's `type` exactly as the backend
 * classifies inflow and outflow.
 */
export function toCashTransaction(
  payload: CashRegisterTransactionPayload,
): CashRegisterTransaction {
  const type = toCashTransactionType(payload.type);

  return {
    id: payload.id,
    cashRegisterId: payload.cash_register_id,
    type,
    amount: toMoney(payload.amount),
    direction: cashTransactionDirection(type),
    referenceType: toText(payload.reference_type),
    referenceId: payload.reference_id,
    note: toText(payload.note),
    createdBy: payload.created_by,
    recordedBy: toText(payload.creator?.name),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of transactions. */
export function toCashTransactions(
  payloads: readonly CashRegisterTransactionPayload[],
): CashRegisterTransaction[] {
  return payloads.map(toCashTransaction);
}

/** `data` of `GET /cash-registers/{id}/summary` → `CashRegisterSummary`. */
export function toCashRegisterSummary(
  payload: CashRegisterSummaryPayload,
): CashRegisterSummary {
  return {
    cashRegisterId: payload.cash_register_id,
    openingBalance: toMoney(payload.opening_balance),
    totalIn: toMoney(payload.total_in),
    totalOut: toMoney(payload.total_out),
    transactionCount: payload.transaction_count,
    currentBalance: toMoney(payload.current_balance),
  };
}
