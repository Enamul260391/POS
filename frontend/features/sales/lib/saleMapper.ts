import { toMoney, toQuantity, toText } from "@/lib/api/values";
import type { Payment, PaymentMethod, PaymentPayload } from "@/types/payment";
import type {
  Sale,
  SaleItem,
  SaleItemPayload,
  SalePaymentStatus,
  SalePayload,
  SaleStatus,
} from "@/types/sale";

import { toCustomer } from "../../customers/lib/customerMapper";
import { toProductUnit } from "../../products/lib/productMapper";

/**
 * `SaleResource` → `Sale`, and `PaymentResource` → `Payment`.
 *
 * Two shapes genuinely differ between the API and the screens:
 *
 *   payment status  the API stores `pending` / `paid` / `partial`; the UI has
 *                   always called the first one `unpaid`.
 *   sale status     the API can answer `returned`, which the older UI type did
 *                   not know about.
 *
 * Everything else is a straight snake_case → camelCase translation, with money
 * converted once through `toMoney` (decimal strings stay strings on the wire).
 * Fields the API does not store (`unitCost`, `payment.provider`,
 * `payment.tenderedAmount`, …) are deliberately left empty rather than
 * invented.
 */

/** Narrows `Sale::STATUSES` onto the UI union. */
export function toSaleStatus(value: string): SaleStatus {
  if (value === "draft" || value === "void" || value === "returned") {
    return value;
  }
  return "completed";
}

/** Narrows `Sale::PAYMENT_STATUSES` onto the UI union (API `pending` ⇒ UI `unpaid`). */
export function toSalePaymentStatus(value: string): SalePaymentStatus {
  if (value === "paid" || value === "partial" || value === "refunded") {
    return value;
  }
  return "unpaid";
}

/**
 * Narrows `Payment::PAYMENT_METHODS`.
 *
 * Anything unknown is treated as cash: it is the only method that ever reaches
 * the drawer, so an unrecognised value can never be mistaken for a card or
 * bank settlement.
 */
export function toPaymentMethod(value: string): PaymentMethod {
  switch (value) {
    case "card":
      return "card";
    case "bank":
      return "bank";
    case "cheque":
      return "cheque";
    case "due":
      return "due";
    case "mobile_banking":
      return "mobile_banking";
    case "store_credit":
      return "store_credit";
    case "bank_transfer":
      return "bank_transfer";
    default:
      return "cash";
  }
}

/** `PaymentResource` → `Payment`. */
export function toPayment(payload: PaymentPayload): Payment {
  return {
    id: payload.id,
    saleId: payload.sale_id ?? 0,
    // The API has no payment reference generator: a payment recorded without
    // one is identified by its own id so the table never shows an empty cell.
    reference: toText(payload.reference) ?? `PAY-${payload.id}`,
    method: toPaymentMethod(payload.payment_method),
    // `payments` has no status column — a stored payment was received.
    status: "completed",
    amount: toMoney(payload.amount),
    // Provider, provider transaction id and the cash change are not persisted
    // by the API, so they stay empty instead of being guessed.
    provider: null,
    transactionId: null,
    tenderedAmount: null,
    changeAmount: null,
    paidAt: payload.paid_at ?? payload.created_at,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** `SaleItemResource` → `SaleItem`. */
export function toSaleItem(payload: SaleItemPayload): SaleItem {
  return {
    id: payload.id,
    saleId: payload.sale_id,
    productId: payload.product_id,
    // The name/sku/unit snapshot only exists when the endpoint eager loaded
    // `items.product` (the detail endpoint does); the list endpoint does not
    // load it, so a fallback keeps the receipt readable.
    productName: payload.product?.name ?? `Product #${payload.product_id}`,
    sku: toText(payload.product?.sku) ?? "—",
    unit: toProductUnit(payload.product?.unit ?? "piece"),
    quantity: toQuantity(payload.quantity),
    unitPrice: toMoney(payload.unit_price),
    discount: toMoney(payload.discount),
    taxAmount: toMoney(payload.tax),
    lineTotal: toMoney(payload.total),
  };
}

/**
 * `SaleResource` → `Sale`.
 *
 * Totals are re-based onto the screenshot the cashier approved:
 *
 *   `sale_items.total` already has the line discount subtracted and the line
 *   tax added, so the API's `subtotal` is *not* the sum of gross line amounts.
 *   When the lines are embedded (detail endpoint) the mapper therefore derives
 *   the UI's three components from them and keeps `grand_total` untouched:
 *
 *     subtotal      Σ (quantity × unitPrice)                gross lines
 *     discountTotal Σ line discounts + invoice discount
 *     taxTotal      Σ line taxes + invoice tax
 *
 *   which satisfies `subtotal − discountTotal + taxTotal = grand_total` exactly,
 *   because that is the same algebra the API applies internally. Without items
 *   (list endpoint) the API's own values are used, and the identity still holds.
 *
 * `items` and `payments` are only read when the relation was eager loaded, so
 * the list endpoint stays free of N+1 queries.
 */
export function toSale(payload: SalePayload): Sale {
  const items = (payload.items ?? []).map(toSaleItem);
  const invoiceDiscount = toMoney(payload.discount);
  const invoiceTax = toMoney(payload.tax);

  const hasItems = items.length > 0;
  const lineGross = roundMoney(
    items.reduce((total, item) => total + item.quantity * item.unitPrice, 0),
  );
  const lineDiscounts = roundMoney(
    items.reduce((total, item) => total + item.discount, 0),
  );
  const lineTaxes = roundMoney(
    items.reduce((total, item) => total + item.taxAmount, 0),
  );

  return {
    id: payload.id,
    // The API is the only authority for an invoice number: it allocates it
    // from the business' own locked sequence when the sale is created and
    // stores it in a `NOT NULL`, unique-per-business column, so the value
    // below is always the persisted one. `toText` merely trims it; the `?? ""`
    // is an unreachable type guard for a malformed payload, and deliberately
    // shows nothing rather than fabricating a `SALE-{id}` identity that looks
    // like a real, unique invoice number but is not one.
    invoiceNumber: toText(payload.invoice_number) ?? "",
    customerId: payload.customer_id,
    customerName: payload.customer?.name ?? null,
    customer: payload.customer ? toCustomer(payload.customer) : null,
    cashierId: payload.cashier_id ?? 0,
    cashierName: toText(payload.cashier?.name),
    status: toSaleStatus(payload.status),
    paymentStatus: toSalePaymentStatus(payload.payment_status),
    items,
    payments: (payload.payments ?? []).map(toPayment),
    subtotal: hasItems ? lineGross : toMoney(payload.subtotal),
    discountTotal: hasItems ? roundMoney(lineDiscounts + invoiceDiscount) : invoiceDiscount,
    taxTotal: hasItems ? roundMoney(lineTaxes + invoiceTax) : invoiceTax,
    grandTotal: toMoney(payload.grand_total),
    paidAmount: toMoney(payload.paid_amount),
    dueAmount: toMoney(payload.due_amount),
    note: toText(payload.notes),
    // The invoice carries a calendar date plus a creation timestamp; the
    // timestamp is the honest "sold at" value a receipt prints.
    soldAt: payload.created_at,
    saleDate: payload.sale_date ?? payload.created_at.slice(0, 10),
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of sales. */
export function toSales(payloads: readonly SalePayload[]): Sale[] {
  return payloads.map(toSale);
}

/** Rounds to two decimals; non-finite input becomes `0`. */
function roundMoney(amount: number): number {
  return Math.round((Number.isFinite(amount) ? amount : 0) * 100) / 100;
}
