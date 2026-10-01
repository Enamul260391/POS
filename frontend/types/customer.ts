/**
 * Customer that can be attached to a sale.
 *
 * A sale without a customer is a walk-in sale (`customerId: null`), which is
 * the most common case in a POS terminal, so every optional detail below is
 * nullable instead of required.
 *
 * Phase 25: the type now mirrors `CustomerResource` exactly. The Phase 16
 * `customers` table has no `notes` column and no aggregate columns, so the
 * previously modelled `notes`, `totalPurchases`, `dueAmount`, `loyaltyPoints`
 * and `lastPurchaseAt` were removed instead of being fed with invented zeroes.
 * `openingBalance` is the one financial field the API does return, and it is a
 * stored value — not a live "outstanding" figure, which the API does not
 * compute.
 */

/** Whether the customer can be selected at checkout. */
export type CustomerStatus = "active" | "inactive";

export interface Customer {
  /** Primary key. */
  id: number;
  /** Display name, e.g. "Rahim Uddin" or "Sultana Traders". */
  name: string;
  /** Primary contact number. `null` when only a name is known. */
  phone: string | null;
  /** Optional email address, used for digital receipts. */
  email: string | null;
  /** Optional postal address. */
  address: string | null;
  /**
   * Opening balance the customer started with (BDT).
   *
   * A stored opening figure, owned by the customer record. It is *not* the
   * live outstanding khata balance: the API does not aggregate unpaid sales, so
   * nothing in the UI may present this as "amount owed".
   */
  openingBalance: number;
  /** Inactive customers are hidden from the checkout search. */
  status: CustomerStatus;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Raw `CustomerResource` payload. */
export interface CustomerPayload {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  opening_balance: string;
  status: string;
  created_at: string;
  updated_at: string;
}
