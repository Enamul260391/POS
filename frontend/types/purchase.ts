import type { ProductUnit } from "@/types/product";

/**
 * A completed purchase document (supplier order that has been received).
 *
 * The shape follows what a Laravel `PurchaseResource` returns — a deliberate
 * mirror of `Sale` (`@/types/sale`): header data, the received lines
 * (`items`), pre-calculated totals and a derived payment status, so the
 * frontend never re-implements business rules such as tax or rounding and the
 * same numbers can be posted to `POST /api/purchases` later.
 */

/**
 * Settlement state of a purchase, derived from the amount paid.
 * - `unpaid`  nothing paid yet
 * - `partial` some payment received, a due remains
 * - `paid`    grand total fully covered
 */
export type PurchasePaymentStatus = "unpaid" | "partial" | "paid";

/**
 * A single received line.
 *
 * Product details are copied onto the line when the purchase is created, so a
 * past order stays correct even after the product is renamed or repriced —
 * the same snapshot rule the cart and sale items follow.
 */
export interface PurchaseItem {
  /** Primary key. */
  id: number;
  /** Id of the purchase this line belongs to. */
  purchaseId: number;
  /** Id of the product that was received. */
  productId: number;
  /** Product name at the time of purchase. */
  productName: string;
  /** Product SKU at the time of purchase. */
  sku: string;
  /** Unit the product is stocked in. */
  unit: ProductUnit;
  /** Quantity received, expressed in `unit`. */
  quantity: number;
  /** Cost per unit agreed for this line (BDT). */
  unitCost: number;
  /** Absolute line discount in currency units. */
  discount: number;
  /** Final line amount: `quantity * unitCost - discount`. */
  lineTotal: number;
}

export interface Purchase {
  /** Primary key. */
  id: number;
  /** Human readable order number, unique per purchase ("PO-20260920-005"). */
  referenceNumber: string;
  /** Id of the supplier this order was placed with. */
  supplierId: number;
  /** Supplier name snapshot at the time of purchase. */
  supplierName: string;
  /** Settlement state derived from `paidAmount`. */
  paymentStatus: PurchasePaymentStatus;
  /** Received lines. Always at least one. */
  items: PurchaseItem[];
  /** Sum of all line amounts before discount. */
  subtotal: number;
  /** Sum of line discounts. */
  discountTotal: number;
  /** Invoice level VAT percentage applied to the discounted subtotal. */
  taxRate: number;
  /** Tax amount: `(subtotal - discountTotal) × taxRate%`. */
  taxTotal: number;
  /** Amount owed for this order: `subtotal - discountTotal + taxTotal`. */
  grandTotal: number;
  /** Amount paid at (or after) creation, clamped to `grandTotal`. */
  paidAmount: number;
  /** Remaining balance: `grandTotal - paidAmount`. Added to the supplier's
   * `dueAmount` when greater than zero. */
  dueAmount: number;
  /** Free text note, e.g. delivery reference. */
  note: string | null;
  /** ISO 8601 timestamp of when the goods were received. */
  purchasedAt: string;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Raw `PurchaseItemResource` payload.
 *
 * Money arrives as a decimal string, quantities as the `Quantity` helper's
 * formatted string, and the `product` relation only when the endpoint eager
 * loaded it (the list endpoint does not).
 */
export interface PurchaseItemPayload {
  id: number;
  purchase_id: number;
  product_id: number;
  quantity: string | number;
  unit_cost: string;
  discount: string;
  tax: string;
  total: string;
  product?: {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
    status?: string;
    [key: string]: unknown;
  } | null;
}

/**
 * Raw `PurchaseResource` payload.
 *
 * Every money field is a server-calculated decimal string — the client only
 * ever supplies inputs (`items`, `tax`, `paid_amount`), never totals.
 * `supplier` and `items` appear only when the query eager loaded them: the
 * list endpoint loads the supplier, the detail endpoint loads the lines with
 * their products.
 */
export interface PurchasePayload {
  id: number;
  supplier_id: number;
  invoice_number: string | null;
  purchase_date: string | null;
  subtotal: string;
  discount: string;
  tax: string;
  grand_total: string;
  paid_amount: string;
  due_amount: string;
  payment_status: string;
  status: string;
  notes: string | null;
  created_by: number | null;
  supplier?: {
    id: number;
    name: string;
    [key: string]: unknown;
  } | null;
  items?: PurchaseItemPayload[];
  created_at: string;
  updated_at: string;
}