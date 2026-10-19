import { toMoney, toNullableMoney, toQuantity, toText } from "@/lib/api/values";
import { toProductUnit } from "@/features/products/lib/productMapper";
import type {
  Purchase,
  PurchaseItem,
  PurchaseItemPayload,
  PurchasePayload,
  PurchasePaymentStatus,
} from "@/types/purchase";

/**
 * `PurchaseResource` → `Purchase`, and `PurchaseItemResource` → `PurchaseItem`.
 *
 * Phase 25 adapter: the API stores the invoice-level `tax` as an absolute
 * amount while the UI displays a VAT percentage, and its subtotal is already
 * net of line discounts whereas the screens show the gross figure. When the
 * lines are embedded (detail endpoint) the mapper therefore rebuilds the UI's
 * components from them and keeps `grand_total` untouched — the same rule
 * `saleMapper` applies, so the identity `subtotal − discountTotal + taxTotal =
 * grandTotal` holds exactly. Without items (list endpoint) the API's own
 * values are used, and the identity still holds.
 *
 * Two more dialect differences are normalised here:
 *
 *   payment status  the API stores `pending` / `paid` / `partial`; the UI has
 *                   always called the first one `unpaid`.
 *   reference       `invoice_number` is optional in the API; a purchase
 *                   without one falls back to `PO-{id}` for display only.
 */

/** Narrows `Purchase::PAYMENT_STATUSES` onto the UI union (API `pending` ⇒ UI `unpaid`). */
export function toPurchasePaymentStatus(value: string): PurchasePaymentStatus {
  if (value === "paid" || value === "partial") {
    return value;
  }
  return "unpaid";
}

/** `PurchaseItemResource` → `PurchaseItem`. */
export function toPurchaseItem(payload: PurchaseItemPayload): PurchaseItem {
  return {
    id: payload.id,
    purchaseId: payload.purchase_id,
    productId: payload.product_id,
    productName: payload.product?.name ?? `Product #${payload.product_id}`,
    sku: toText(payload.product?.sku) ?? "—",
    unit: toProductUnit(payload.product?.unit ?? "piece"),
    quantity: toQuantity(payload.quantity),
    unitCost: toMoney(payload.unit_cost),
    discount: toMoney(payload.discount),
    // Historic rows (and untouched new rows) omit these as `null` — they must
    // read as absent (`null`), never `0` or `NaN`, and never shift totals.
    mrp: toNullableMoney(payload.mrp),
    sellingPrice: toNullableMoney(payload.selling_price),
    sellingCode: toText(
      typeof payload.selling_code === "string" ? payload.selling_code : null,
    ),
    lineTotal: toMoney(payload.total),
  };
}

/** Rounds to two decimals; non-finite input becomes `0`. */
function roundMoney(amount: number): number {
  return Math.round((Number.isFinite(amount) ? amount : 0) * 100) / 100;
}

/**
 * `PurchaseResource` → `Purchase`.
 *
 * The displayed VAT percentage is recovered from the stored amounts
 * (`tax / (subtotal − discount)`), because the API keeps only the absolute
 * tax figure the service calculated.
 */
export function toPurchase(payload: PurchasePayload): Purchase {
  const items = (payload.items ?? []).map(toPurchaseItem);
  const headerDiscount = toMoney(payload.discount);
  const hasItems = items.length > 0;

  const lineGross = roundMoney(
    items.reduce((total, item) => total + item.quantity * item.unitCost, 0),
  );
  const lineDiscounts = roundMoney(
    items.reduce((total, item) => total + item.discount, 0),
  );

  const subtotal = hasItems ? lineGross : toMoney(payload.subtotal);
  const discountTotal = hasItems
    ? roundMoney(lineDiscounts + headerDiscount)
    : headerDiscount;
  const taxTotal = toMoney(payload.tax);
  const taxable = roundMoney(subtotal - discountTotal);
  const taxRate = taxable > 0 && taxTotal > 0
    ? roundMoney((taxTotal / taxable) * 100)
    : 0;

  return {
    id: payload.id,
    referenceNumber: toText(payload.invoice_number) ?? `PO-${payload.id}`,
    supplierId: payload.supplier_id,
    supplierName: payload.supplier?.name ?? `Supplier #${payload.supplier_id}`,
    paymentStatus: toPurchasePaymentStatus(payload.payment_status),
    items,
    subtotal,
    discountTotal,
    taxRate,
    taxTotal,
    grandTotal: toMoney(payload.grand_total),
    paidAmount: toMoney(payload.paid_amount),
    dueAmount: toMoney(payload.due_amount),
    note: toText(payload.notes),
    // The stored document's own timestamp: honest about when it was recorded,
    // and always a full moment (unlike `purchase_date`, a bare calendar day).
    purchasedAt: payload.created_at,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of purchases. */
export function toPurchases(payloads: readonly PurchasePayload[]): Purchase[] {
  return payloads.map(toPurchase);
}