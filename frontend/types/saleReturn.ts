import type { PaymentMethod } from "@/types/payment";
import type { ProductUnit } from "@/types/product";

/**
 * A customer return recorded against a completed sale.
 *
 * Created by the return flow on `/sales`: the cashier picks the returned lines,
 * a quantity and a reason per line, and the refund is derived from the stored
 * invoice — never retyped. The shape follows what a Laravel `SaleReturnResource`
 * returns: header data, the returned lines (`items`) and the refund, so the
 * mock document is replaced by an API response without UI changes.
 *
 * The sale itself is never mutated: an invoice is a historical record, so the
 * returned quantities live here and are netted off in the UI.
 */

/** Why a line came back — maps onto a `sale_return_items.reason` enum. */
export type SaleReturnReason =
  | "damaged"
  | "expired"
  | "wrong_item"
  | "not_as_described"
  | "customer_changed_mind"
  | "other";

/**
 * A returned quantity taken from one invoice line.
 *
 * Product details are copied from the sale line — not re-read from the
 * catalogue — so a return stays correct even if the product was renamed,
 * repriced or deleted afterwards.
 */
export interface SaleReturnItem {
  /** Primary key. */
  id: number;
  /** Id of the return this line belongs to. */
  returnId: number;
  /** Id of the invoice line the quantity came from. */
  saleItemId: number;
  /** Id of the product that was returned. */
  productId: number;
  /** Product name snapshot from the invoice line. */
  productName: string;
  /** Product SKU snapshot from the invoice line. */
  sku: string;
  /** Unit the quantity is expressed in. */
  unit: ProductUnit;
  /** Quantity taken back, in `unit`. */
  quantity: number;
  /** Selling price per unit on the invoice line. */
  unitPrice: number;
  /** Money refunded for this line (a share of the invoice line total). */
  refundAmount: number;
  /** Why the customer returned it. */
  reason: SaleReturnReason;
  /**
   * `true` when the goods were put back on the shelf. Drives the inventory
   * stock reversal (a `receive` movement); damaged or expired goods stay out
   * of stock.
   */
  restocked: boolean;
}

export interface SaleReturn {
  /** Primary key. */
  id: number;
  /** Human readable return number, unique per return ("RET-20260922-001"). */
  reference: string;
  /** Id of the sale the goods came from. */
  saleId: number;
  /** Invoice number snapshot, so the return stays readable on its own. */
  invoiceNumber: string;
  /** Id of the customer, `null` for a walk-in sale. */
  customerId: number | null;
  /** Customer name snapshot, when the API embedded the sale's customer. */
  customerName: string | null;
  /** Returned lines. Always at least one. */
  items: SaleReturnItem[];
  /** Total money given back to the customer. */
  refundTotal: number;
  /**
   * How the money was returned, or `null`.
   *
   * `sale_returns` has no refund-method column, so a return that came back
   * from the API is always `null` — the UI shows "—" instead of guessing a
   * method the backend never recorded.
   */
  refundMethod: PaymentMethod | null;
  /** Provider name for non cash refunds, e.g. "bKash", else `null`. */
  refundProvider: string | null;
  /** Free text reason / note recorded on the return document. */
  note: string | null;
  /** Display name of the user who processed the return, when the API sends it. */
  processedBy: string | null;
  /** ISO 8601 timestamp of when the return was accepted. */
  returnedAt: string;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Raw `SaleReturnResource` payload.
 *
 * `sale` and `items` are relation objects that only appear when the endpoint
 * eager loaded them; `reason` is a single free-text field for the whole return
 * (there is no per-line reason column).
 */
export interface SaleReturnItemPayload {
  id: number;
  sale_item_id: number;
  product_id: number;
  quantity: string | number;
  unit_price: string;
  total: string;
  product?: {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
  } | null;
}

export interface SaleReturnPayload {
  id: number;
  sale_id: number;
  customer_id: number | null;
  return_number: string | null;
  return_date: string | null;
  reason: string | null;
  subtotal: string;
  tax: string;
  refund_amount: string;
  status: string;
  sale?: {
    id: number;
    invoice_number: string | null;
    customer?: { id: number; name: string } | null;
  } | null;
  items?: SaleReturnItemPayload[];
  created_at: string;
  updated_at: string;
}
