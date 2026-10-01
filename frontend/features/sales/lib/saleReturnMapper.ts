import { toMoney, toQuantity, toText } from "@/lib/api/values";
import type {
  SaleReturn,
  SaleReturnItem,
  SaleReturnItemPayload,
  SaleReturnPayload,
  SaleReturnReason,
} from "@/types/saleReturn";

import { toProductUnit } from "../../products/lib/productMapper";
import { RETURN_REASON_LABELS, RETURN_REASON_OPTIONS } from "./returns";

/**
 * `SaleReturnResource` → `SaleReturn`.
 *
 * The API's return document is thinner than the UI's: there is one free-text
 * `reason` for the whole return (no per-line reason, no restock flag and no
 * refund method). The mapper therefore:
 *
 *   - resolves `invoiceNumber` from the embedded sale,
 *   - stores the free-text reason in `note`,
 *   - attributes the reason to each line when the text starts with a known
 *     label, otherwise falls back to `other`,
 *   - marks every line `restocked`, which is what `SaleReturnService` does
 *     (it writes a stock-in movement per returned line that tracks stock),
 *   - leaves `refundMethod` `null`, because nothing was recorded.
 *
 * Nothing is invented: every value either comes from the response or documents
 * a behaviour the backend performs unconditionally.
 */

/** Reverse lookup: composer label → reason enum. */
const REASON_BY_LABEL: ReadonlyMap<string, SaleReturnReason> = new Map(
  RETURN_REASON_OPTIONS.map((option) => [option.label, option.value]),
);

/**
 * Reads the reason enum back out of the free-text `reason` column.
 *
 * A return can carry several reasons (the dialog composes them with ", "), in
 * which case the first one wins; anything unrecognised is `other`.
 */
export function toReturnReason(value: string | null): SaleReturnReason {
  if (value === null || value.trim() === "") {
    return "other";
  }
  const first = value.split(",")[0]?.trim() ?? "";
  return REASON_BY_LABEL.get(first) ?? "other";
}

/** Human wording of a reason, tolerant of a `null` note. */
export function returnReasonLabel(reason: SaleReturnReason): string {
  return RETURN_REASON_LABELS[reason];
}

/** `SaleReturnItemResource` → `SaleReturnItem`. */
export function toSaleReturnItem(
  payload: SaleReturnItemPayload,
  returnId: number,
  reason: SaleReturnReason,
): SaleReturnItem {
  return {
    id: payload.id,
    returnId,
    saleItemId: payload.sale_item_id,
    productId: payload.product_id,
    productName: payload.product?.name ?? `Product #${payload.product_id}`,
    sku: toText(payload.product?.sku) ?? "—",
    unit: toProductUnit(payload.product?.unit ?? "piece"),
    quantity: toQuantity(payload.quantity),
    unitPrice: toMoney(payload.unit_price),
    refundAmount: toMoney(payload.total),
    reason,
    // `SaleReturnService` restocks every returned line whose product tracks
    // stock; the API stores no flag of its own.
    restocked: true,
  };
}

/** `SaleReturnResource` → `SaleReturn`. */
export function toSaleReturn(payload: SaleReturnPayload): SaleReturn {
  const note = toText(payload.reason);
  const reason = toReturnReason(note);

  return {
    id: payload.id,
    reference: toText(payload.return_number) ?? `RET-${payload.id}`,
    saleId: payload.sale_id,
    // A snapshot of the *sale's* persisted invoice number, never one derived
    // here. The sale relation may be absent from the payload, in which case
    // this shows nothing rather than inventing a number for a sale it has not
    // seen.
    invoiceNumber: toText(payload.sale?.invoice_number) ?? "",
    customerId: payload.customer_id,
    customerName: toText(payload.sale?.customer?.name),
    items: (payload.items ?? []).map((item) => toSaleReturnItem(item, payload.id, reason)),
    refundTotal: toMoney(payload.refund_amount),
    // Not recorded by the API — see the type's own documentation.
    refundMethod: null,
    refundProvider: null,
    note,
    processedBy: null,
    returnedAt: payload.return_date ?? payload.created_at,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

/** Convenience wrapper for a whole page of returns. */
export function toSaleReturns(payloads: readonly SaleReturnPayload[]): SaleReturn[] {
  return payloads.map(toSaleReturn);
}
