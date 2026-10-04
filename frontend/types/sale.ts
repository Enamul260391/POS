import type { Customer, CustomerPayload } from "@/types/customer";
import type { Payment, PaymentPayload } from "@/types/payment";
import type { ProductUnit } from "@/types/product";

/**
 * A completed (or voided) sale document.
 *
 * The shape follows what the Laravel `SaleResource` returns: header data,
 * the sold lines (`items`) and the payments that settled them (`payments`),
 * plus pre-calculated totals so the frontend never re-implements business
 * rules such as stock movement, tax or rounding.
 *
 * Field names are camelCase while the API is `snake_case`; the conversion
 * lives in `features/sales/lib/saleMapper.ts` (`toSale`), never in a
 * component.
 *
 * Phase 25 notes:
 * - `status` mirrors `Sale::STATUSES` (`draft` / `completed` / `returned`);
 *   `void` is kept because the screens already render it, and an unknown
 *   server value falls back to `completed`.
 * - `paymentStatus` mirrors `Sale::PAYMENT_STATUSES`, where the API's
 *   `pending` is the UI's `unpaid`.
 * - `unitCost` is still not mapped here: `sale_items` now snapshots the unit
 *   cost, but `SaleItemResource` does not expose it, and cost figures stay in
 *   the domain of `GET /reports/profit-loss`.
 * - `cashierName` is filled from the `cashier` relation when the API embeds
 *   it, so a sale never shows a bare id.
 */

/**
 * Lifecycle state of a sale.
 * - `draft`     held/billed but not finalised (parked sale)
 * - `completed` finalised; stock and reports were updated
 * - `returned`  the API marks a sale that has been returned
 * - `void`      cancelled after creation; kept for audit
 */
export type SaleStatus = "draft" | "completed" | "returned" | "void";

/**
 * Settlement state of a sale, derived by the API from its payments.
 * - `unpaid`   no payment received yet (API: `pending`)
 * - `partial`  some payment received, a due remains
 * - `paid`     grand total fully covered
 * - `refunded` money was returned to the customer
 */
export type SalePaymentStatus = "unpaid" | "partial" | "paid" | "refunded";

/**
 * A single line of a sale.
 *
 * Product details are copied onto the line when the sale is created, so an old
 * invoice stays correct even after the product is renamed, repriced or deleted.
 * `productName`, `sku` and `unit` come from the line's embedded `product`
 * relation; the ledger is the only place a stock level comes from.
 */
export interface SaleItem {
  /** Primary key. */
  id: number;
  /** Id of the sale this line belongs to. */
  saleId: number;
  /** Id of the product that was sold. */
  productId: number;
  /** Product name at the time of sale. */
  productName: string;
  /** Product SKU at the time of sale. */
  sku: string;
  /** Unit the product was sold in. */
  unit: ProductUnit;
  /** Quantity sold, expressed in `unit`. */
  quantity: number;
  /** Selling price per unit used for this line. */
  unitPrice: number;
  /** Absolute line discount in currency units. */
  discount: number;
  /** Tax charged for this line. */
  taxAmount: number;
  /** Final line amount: `quantity * unitPrice - discount + taxAmount`. */
  lineTotal: number;
}

export interface Sale {
  /** Primary key. */
  id: number;
  /**
   * Human readable invoice number, unique per business.
   *
   * Always the number the API generated and persisted for this sale, in the
   * documented `{invoice_prefix}-{000000}` form (e.g. `"INV-000042"`). The
   * frontend never derives, predicts or invents one.
   */
  invoiceNumber: string;
  /** Id of the customer, `null` for a walk-in sale. */
  customerId: number | null;
  /** Customer name snapshot, printed on the receipt for walk-in sales too. */
  customerName: string | null;
  /**
   * The attached customer, when the API embedded the relation.
   *
   * The detail endpoint loads it, so the invoice screen reads the phone,
   * address and opening balance from the sale response itself instead of
   * issuing a second request. `null` for a walk-in sale or a list row.
   */
  customer: Customer | null;
  /** Id of the user (cashier) who completed the sale. */
  cashierId: number;
  /** Cashier display name, when the API embedded the relation. */
  cashierName: string | null;
  /** Lifecycle state of the sale. */
  status: SaleStatus;
  /** Settlement state derived from `payments`. */
  paymentStatus: SalePaymentStatus;
  /** Sold lines. Always at least one for a finalised sale. */
  items: SaleItem[];
  /** Payments that settled this sale; may be empty while unpaid. */
  payments: Payment[];
  /** Sum of all line amounts before discount and tax. */
  subtotal: number;
  /** Sum of line and invoice level discounts. */
  discountTotal: number;
  /** Sum of all line taxes. */
  taxTotal: number;
  /** Amount the customer has to pay: `subtotal - discountTotal + taxTotal`. */
  grandTotal: number;
  /** Sum of the payments received so far. */
  paidAmount: number;
  /** Remaining balance: `grandTotal - paidAmount`. */
  dueAmount: number;
  /** Free text note, printed on the receipt when present. */
  note: string | null;
  /** ISO 8601 timestamp of when the sale was completed. */
  soldAt: string;
  /** Calendar date of the sale (`sale_date`, `YYYY-MM-DD`). */
  saleDate: string;
  /** ISO 8601 timestamp of creation. */
  createdAt: string;
  /** ISO 8601 timestamp of the last update. */
  updatedAt: string;
}

/**
 * Raw `SaleResource` payload.
 *
 * Money arrives as a decimal string (`"1005.00"`), quantities as the
 * `Quantity` helper's formatted string, and relations (`customer`, `items`,
 * `items[].product`, `payments`, `cashier`) only when the endpoint eager
 * loaded them — the list endpoint does not.
 */
export interface SaleItemPayload {
  id: number;
  sale_id: number;
  product_id: number;
  quantity: string | number;
  unit_price: string;
  discount: string;
  tax: string;
  total: string;
  product?: {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
  } | null;
}

export interface SalePayload {
  id: number;
  customer_id: number | null;
  /**
   * The persisted invoice number, e.g. `"INV-000042"`.
   *
   * Typed as possibly-`null` only because the wire format is not narrowed by
   * TypeScript; the column is `NOT NULL`, so a real response always carries a
   * value. See `Sale.invoiceNumber`.
   */
  invoice_number: string | null;
  sale_date: string | null;
  subtotal: string;
  discount: string;
  tax: string;
  grand_total: string;
  paid_amount: string;
  due_amount: string;
  payment_status: string;
  status: string;
  notes: string | null;
  cashier_id: number | null;
  customer?: CustomerPayload | null;
  cashier?: { id: number; name: string } | null;
  items?: SaleItemPayload[];
  payments?: PaymentPayload[];
  created_at: string;
  updated_at: string;
}

