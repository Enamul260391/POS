import { PAYMENT_METHOD_LABELS } from "@/features/sales/lib/sales";
import type { PaymentMethod } from "@/types/payment";
import type { Sale, SaleItem } from "@/types/sale";
import type { SaleReturn, SaleReturnReason } from "@/types/saleReturn";

/**
 * Pure helpers for the return flow (Phase 10).
 *
 * One place owns the two questions the flow asks about money and quantities:
 *
 *   "how much do we give back?"      → `refundForQuantity` / `calculateReturnTotals`
 *   "how much may still come back?"  → `returnableQuantities` / `remainingReturnableQuantity`
 *
 * The refund is a proportional share of the invoice line total, so the money
 * the customer gets back can never be retyped and never drifts: line total
 * (after its discount and tax) divided by the quantity sold, times the
 * quantity returned. The return dialog previews with these functions and
 * `salesService` stores with the same ones — preview ≡ saved, exactly like the
 * cart totals and the purchase totals before it.
 */

/** Reasons offered on every returned line. */
export const RETURN_REASON_OPTIONS: readonly {
  value: SaleReturnReason;
  label: string;
}[] = [
  { value: "damaged", label: "Damaged / broken" },
  { value: "expired", label: "Expired" },
  { value: "wrong_item", label: "Wrong item delivered" },
  { value: "not_as_described", label: "Not as described" },
  { value: "customer_changed_mind", label: "Customer changed mind" },
  { value: "other", label: "Other" },
];

/** Wording of the reason enum, shared by the dialog and the return history. */
export const RETURN_REASON_LABELS: Readonly<Record<SaleReturnReason, string>> = {
  damaged: "Damaged / broken",
  expired: "Expired",
  wrong_item: "Wrong item delivered",
  not_as_described: "Not as described",
  customer_changed_mind: "Customer changed mind",
  other: "Other",
};

/** How the refund was handed back, when the API reported one. */
export const REFUND_METHOD_OPTIONS: readonly { value: PaymentMethod; label: string }[] = [
  { value: "cash", label: PAYMENT_METHOD_LABELS.cash },
  { value: "card", label: PAYMENT_METHOD_LABELS.card },
  { value: "bank", label: PAYMENT_METHOD_LABELS.bank },
  { value: "cheque", label: PAYMENT_METHOD_LABELS.cheque },
];

/** Default reason of a newly ticked line. */
export const DEFAULT_RETURN_REASON: SaleReturnReason = "customer_changed_mind";

/** Rounds to two decimals; non-finite input becomes `0`. */
export function roundMoney(amount: number): number {
  return Math.round((Number.isFinite(amount) ? amount : 0) * 100) / 100;
}

/**
 * Money refunded for `quantity` units of one invoice line.
 *
 * A proportional share of `lineTotal`, which already accounts for that line's
 * discount and tax, so partial returns stay exact and their shares always add
 * up to the refund total. Quantities above what was sold are capped.
 */
export function refundForQuantity(item: SaleItem, quantity: number): number {
  if (!Number.isFinite(quantity) || quantity <= 0 || item.quantity <= 0) {
    return 0;
  }
  const capped = Math.min(quantity, item.quantity);
  return roundMoney((item.lineTotal / item.quantity) * capped);
}
/** One line as the return form holds it, reduced to what the totals need. */
export interface ReturnLineInput {
  item: SaleItem;
  quantity: number;
  /** `true` when the goods go back on the shelf (stock reversal). */
  restock: boolean;
}

/** Everything derived from one return selection. */
export interface SaleReturnTotals {
  /** Lines that actually come back (quantity greater than zero). */
  lines: number;
  /** Sum of the returned quantities. */
  quantities: number;
  /** Returned quantities that go back on the shelf. */
  restockedQuantities: number;
  /** Money to hand back: `Σ` the proportional line refunds. */
  refundTotal: number;
}

/** The one refund calculation shared by the dialog preview and the service. */
export function calculateReturnTotals(
  lines: readonly ReturnLineInput[],
): SaleReturnTotals {
  let lineCount = 0;
  let quantities = 0;
  let restockedQuantities = 0;
  let refundTotal = 0;

  for (const line of lines) {
    const quantity = Number.isFinite(line.quantity) && line.quantity > 0 ? line.quantity : 0;
    if (quantity <= 0) {
      continue;
    }
    lineCount += 1;
    quantities += quantity;
    if (line.restock) {
      restockedQuantities += quantity;
    }
    refundTotal = roundMoney(refundTotal + refundForQuantity(line.item, quantity));
  }

  return { lines: lineCount, quantities, restockedQuantities, refundTotal };
}

/** Quantity that may still be returned of one invoice line. */
export function remainingReturnableQuantity(
  item: SaleItem,
  alreadyReturned: number,
): number {
  const returned = Number.isFinite(alreadyReturned) && alreadyReturned > 0 ? alreadyReturned : 0;
  return Math.max(0, Math.round((item.quantity - returned) * 1000) / 1000);
}

/**
 * Remaining returnable quantity per invoice line id of one sale, netting off
 * every return already recorded for it. Lines absent from the map are fully
 * returnable — the dialog starts from the sale document.
 */
export function returnableQuantities(
  sale: Sale,
  saleReturns: readonly SaleReturn[],
): Map<number, number> {
  const returned = new Map<number, number>();
  for (const saleReturn of saleReturns) {
    if (saleReturn.saleId !== sale.id) {
      continue;
    }
    for (const item of saleReturn.items) {
      returned.set(item.saleItemId, (returned.get(item.saleItemId) ?? 0) + item.quantity);
    }
  }

  const returnable = new Map<number, number>();
  for (const item of sale.items) {
    returnable.set(item.id, remainingReturnableQuantity(item, returned.get(item.id) ?? 0));
  }
  return returnable;
}

/** Returns recorded against one sale, newest first. */
export function saleReturnsFor(
  saleReturns: readonly SaleReturn[],
  saleId: number,
): SaleReturn[] {
  return saleReturns.filter((saleReturn) => saleReturn.saleId === saleId);
}

/** Money already refunded on one sale. */
export function refundTotalForSale(
  saleReturns: readonly SaleReturn[],
  saleId: number,
): number {
  return saleReturnsFor(saleReturns, saleId).reduce(
    (total, saleReturn) => roundMoney(total + saleReturn.refundTotal),
    0,
  );
}

/** Display label of a refund method, e.g. `Mobile banking`. */
export function refundMethodLabel(method: PaymentMethod): string {
  return (
    REFUND_METHOD_OPTIONS.find((option) => option.value === method)?.label ??
    PAYMENT_METHOD_LABELS[method]
  );
}

/**
 * How a refund was paid out, or `—`.
 *
 * `sale_returns` stores neither a method nor a provider, so a return that came
 * back from the API shows `—` rather than a method nobody recorded.
 */
export function saleReturnRefundLabel(saleReturn: SaleReturn): string {
  if (saleReturn.refundProvider?.trim()) {
    return saleReturn.refundProvider.trim();
  }
  return saleReturn.refundMethod === null
    ? "—"
    : refundMethodLabel(saleReturn.refundMethod);
}

/** Returned units of one sale return — the "Items" column of the history. */
export function returnUnitCount(saleReturn: SaleReturn): number {
  return saleReturn.items.reduce((total, item) => total + item.quantity, 0);
}

/** Units of one return that went back on the shelf (the stock reversal). */
export function restockedUnitCount(saleReturn: SaleReturn): number {
  return saleReturn.items.reduce(
    (total, item) => (item.restocked ? total + item.quantity : total),
    0,
  );
}

