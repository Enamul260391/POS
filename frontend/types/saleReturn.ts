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

/**
 * How one return was settled, as the API recorded it.
 *
 * `refunded` and `credited` always add up to the return's `refund_amount`: a
 * return either paid money out, relieved a debt, or split between the two — it
 * is never neither and never both. `byMethod` is the per-method split of the
 * money that actually left the business, which is why it sums to `refunded`.
 */
export interface SaleReturnSettlement {
  /** Money that left the business. */
  refunded: number;
  /**
   * Money no method could pay back, relieved on the customer's account instead.
   * Zero for a return that was settled in full by refunds.
   */
  credited: number;
  /** Per-method split of `refunded`, e.g. `{ cash: 400, card: 100 }`. */
  byMethod: Partial<Record<PaymentMethod, number>>;
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
   * How the money was actually handed back, or `null` when the API did not
   * report a settlement (the list view, which does not load the refund rows).
   *
   * Populated from the return's own `settlement`, so this is what the backend
   * decided rather than a guess: the per-method breakdown always sums to
   * `refunded`, and `credited` is the part no method could cover and that was
   * relieved on the customer's account instead.
   */
  settlement: SaleReturnSettlement | null;
  /**
   * Free text provider name for a non-cash refund, e.g. "bKash", else `null`.
   *
   * The API records the method rather than a provider name, so this stays
   * `null` for a return that came from the API.
   */
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

/** Raw `settlement` block of a `SaleReturnResource`. */
export interface SaleReturnSettlementPayload {
  refunded: string;
  credited: string;
  by_method: Record<string, string>;
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
  /**
   * Present only when the endpoint eager-loaded the refund payments. The list
   * view does not, so it is absent there rather than null — which is why the
   * UI type is `SaleReturnSettlement | null`.
   */
  settlement?: SaleReturnSettlementPayload;
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
