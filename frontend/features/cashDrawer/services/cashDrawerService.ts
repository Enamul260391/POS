import { apiGet, apiList, apiPost } from "@/lib/api/request";
import type { ApiRequestConfig, PaginatedData } from "@/lib/api/request";
import type { ApiError } from "@/types/api";
import type {
  CashRegister,
  CashRegisterPayload,
  CashRegisterSummary,
  CashRegisterSummaryPayload,
  CashRegisterTransaction,
  CashRegisterTransactionPayload,
  CashTransactionType,
} from "@/types/cashDrawer";

import {
  toCashRegister,
  toCashRegisterSummary,
  toCashTransaction,
  toCashTransactions,
} from "../lib/cashRegisterMapper";

/**
 * Cash register application service — the only module that talks to
 * `/cash-registers`.
 *
 * Every function maps one-to-one onto an existing route in `routes/api.php`; no
 * endpoint is invented, renamed or duplicated here.
 *
 * Tenancy and ownership: **no** request carries `business_id`, `opened_by` or
 * `closed_by`. The API resolves the tenant from the bearer token and refuses a
 * register the caller does not own, so a foreign register is indistinguishable
 * from a missing one.
 *
 * Automatic postings are the backend's job and are never replicated here: a cash
 * sale is written by `SaleService::create` and a cash Record Payment by
 * `PaymentService::recordForSale`, each inside its own database transaction. This
 * service only *reads* those rows back through `listTransactions`, so the drawer
 * can never double-count them.
 */

/** Body of `POST /cash-registers/open` (`OpenCashRegisterRequest`). */
export interface OpenRegisterInput {
  /** Counted float; the API requires a number of at least `0`. */
  openingBalance: number;
  notes?: string | null;
}

/** Body of `POST /cash-registers/{id}/close` (`CloseCashRegisterRequest`). */
export interface CloseRegisterInput {
  /** Counted cash; the API requires a number of at least `0`. */
  closingBalance: number;
  notes?: string | null;
}

/** Body of `POST /cash-registers/{id}/transactions`. */
export interface ManualTransactionInput {
  /** Only `cash_in` and `cash_out` are accepted by the API. */
  type: "cash_in" | "cash_out";
  /** Strictly positive; the API rejects zero and negatives. */
  amount: number;
  note?: string | null;
}

/** Query parameters accepted by `GET /cash-registers/{id}/transactions`. */
export interface CashTransactionFilters {
  /** Restricts the page to one transaction type. */
  type?: CashTransactionType;
  page?: number;
  perPage?: number;
}

/** `true` when a rejection is the API's "no open register" `404`. */
function isNoOpenRegister(error: unknown): boolean {
  if (typeof error !== "object" || error === null || !("status" in error)) {
    return false;
  }
  return (error as ApiError).status === 404;
}

/**
 * `GET /cash-registers/current` — the authenticated user's open register.
 *
 * A closed drawer is a normal state, not a failure: the API answers `404` with
 * "No cash register is currently open." and that is translated into `null` so
 * the screen can show its empty state. Every other status still rejects, so a
 * genuine outage is never mistaken for "no register".
 */
export async function getCurrentRegister(
  config?: ApiRequestConfig,
): Promise<CashRegister | null> {
  try {
    const payload = await apiGet<CashRegisterPayload>("/cash-registers/current", {
      signal: config?.signal,
    });
    return toCashRegister(payload);
  } catch (error) {
    if (isNoOpenRegister(error)) {
      return null;
    }
    throw error;
  }
}

/** `GET /cash-registers/{id}` — one register by id. */
export async function getRegister(
  registerId: number,
  config?: ApiRequestConfig,
): Promise<CashRegister> {
  const payload = await apiGet<CashRegisterPayload>(
    `/cash-registers/${registerId}`,
    { signal: config?.signal },
  );
  return toCashRegister(payload);
}

/** `GET /cash-registers/{id}/summary` — the server-side inflow/outflow aggregate. */
export async function getRegisterSummary(
  registerId: number,
  config?: ApiRequestConfig,
): Promise<CashRegisterSummary> {
  const payload = await apiGet<CashRegisterSummaryPayload>(
    `/cash-registers/${registerId}/summary`,
    { signal: config?.signal },
  );
  return toCashRegisterSummary(payload);
}

/** `GET /cash-registers/{id}/transactions` — one page, newest first. */
export async function listTransactions(
  registerId: number,
  filters?: CashTransactionFilters,
  config?: ApiRequestConfig,
): Promise<PaginatedData<CashRegisterTransaction>> {
  const data = await apiList<CashRegisterTransactionPayload>(
    `/cash-registers/${registerId}/transactions`,
    {
      params: {
        type: filters?.type,
        page: filters?.page,
        per_page: filters?.perPage,
      },
      signal: config?.signal,
    },
  );

  return { items: toCashTransactions(data.items), pagination: data.pagination };
}

/**
 * `POST /cash-registers/{id}/transactions` — a manual cash in / cash out.
 *
 * This is the adjustment endpoint. It is deliberately *not* used for a sale or a
 * Record Payment: the API posts those itself, so calling it for them would
 * double-count the cash.
 */
export async function recordManualTransaction(
  registerId: number,
  input: ManualTransactionInput,
): Promise<CashRegisterTransaction> {
  const payload = await apiPost<CashRegisterTransactionPayload>(
    `/cash-registers/${registerId}/transactions`,
    { type: input.type, amount: input.amount, note: input.note ?? null },
  );
  return toCashTransaction(payload);
}

/** `POST /cash-registers/open` — opens a register with the counted float. */
export async function openRegister(
  input: OpenRegisterInput,
): Promise<CashRegister> {
  const payload = await apiPost<CashRegisterPayload>("/cash-registers/open", {
    opening_balance: input.openingBalance,
    notes: input.notes ?? null,
  });
  return toCashRegister(payload);
}

/**
 * `POST /cash-registers/{id}/close` — counts the drawer and closes the shift.
 *
 * The API computes and persists `expected_balance` and `difference` itself, so
 * the client sends only what the cashier counted. A repeat call is refused by
 * the backend with "Cash register is already closed.", surfaced verbatim.
 */
export async function closeRegister(
  registerId: number,
  input: CloseRegisterInput,
): Promise<CashRegister> {
  const payload = await apiPost<CashRegisterPayload>(
    `/cash-registers/${registerId}/close`,
    { closing_balance: input.closingBalance, notes: input.notes ?? null },
  );
  return toCashRegister(payload);
}


