/**
 * Supplier that products are purchased from.
 *
 * Phase 25: the type mirrors `SupplierResource` exactly. The `suppliers` table
 * has no `contact_person`, no `notes` and no aggregate "amount owed" column, so
 * those were removed rather than fed with invented zeroes — the same decision
 * `Customer` made. What the API *does* own is `opening_balance`, a stored
 * figure on the supplier record, plus the `status` that the POS-side pickers
 * filter on.
 *
 * Deliberately shaped like `Customer` (`@/types/customer`): one directory row
 * with contact details and one stored opening figure.
 */

/** Whether the supplier can be selected on a purchase. */
export type SupplierStatus = "active" | "inactive";

export interface Supplier {
  /** Primary key. */
  id: number;
  /** Trading name, e.g. "Metro Beverage Depot". */
  name: string;
  /** Primary contact number. `null` when only a name is known. */
  phone: string | null;
  /** Optional email address for orders and invoices. */
  email: string | null;
  /** Optional postal address / warehouse location. */
  address: string | null;
  /**
   * Opening balance the supplier started with (BDT).
   *
   * A stored figure owned by the supplier record. It is *not* a live "amount we
   * still owe" total: the API does not aggregate unpaid purchases, so nothing in
   * the UI may present it as an outstanding balance.
   */
  openingBalance: number;
  /** Inactive suppliers are hidden from the purchase picker. */
  status: SupplierStatus;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/** Raw `SupplierResource` payload. */
export interface SupplierPayload {
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