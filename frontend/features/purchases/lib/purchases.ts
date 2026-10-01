import type {
  Purchase,
  PurchasePaymentStatus,
} from "@/types/purchase";

/**
 * Pure helpers for the purchase screens and the purchase service (Phase 9).
 *
 * `calculatePurchaseTotals` is the single source of money truth: the create
 * dialog uses it for the live preview and `purchaseService` uses it to build
 * the stored document, so the numbers a buyer sees are exactly the numbers
 * that get saved — the same "derived on read, never stored twice" rule the
 * cart totals follow. Money is rounded to two decimals at every step to keep
 * the mock free of floating point drift.
 */

/** Filter options for the payment status select, preceded by "All". */
export const PAYMENT_STATUS_FILTER_OPTIONS: readonly {
  value: "all" | PurchasePaymentStatus;
  label: string;
}[] = [
  { value: "all", label: "All payments" },
  { value: "paid", label: "Paid" },
  { value: "partial", label: "Partial" },
  { value: "unpaid", label: "Unpaid" },
];

/** Current filter state of the purchase list. */
export interface PurchaseTableFilters {
  /** Free text over reference number and supplier name. */
  query: string;
  /** Payment status filter; `"all"` disables it. */
  paymentStatus: "all" | PurchasePaymentStatus;
}

/**
 * Case-insensitive search over reference number and supplier name plus a
 * payment status filter — one pass so callers can memoise on the result.
 */
export function filterPurchases(
  purchases: readonly Purchase[],
  filters: PurchaseTableFilters,
): Purchase[] {
  const needle = filters.query.trim().toLowerCase();
  return purchases.filter((purchase) => {
    if (
      filters.paymentStatus !== "all" &&
      purchase.paymentStatus !== filters.paymentStatus
    ) {
      return false;
    }
    if (needle === "") {
      return true;
    }
    return (
      purchase.referenceNumber.toLowerCase().includes(needle) ||
      purchase.supplierName.toLowerCase().includes(needle)
    );
  });
}

/** Rounds to two decimals; non-finite input becomes `0`. */
export function roundMoney(amount: number): number {
  return Math.round((Number.isFinite(amount) ? amount : 0) * 100) / 100;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

/** The subset of a line the totals need — works for form rows and items. */
export interface PurchaseLineLike {
  quantity: number;
  unitCost: number;
  discount: number;
}

/** Inputs of one totals calculation. */
export interface PurchaseTotalsInput {
  lines: readonly PurchaseLineLike[];
  /** Invoice level VAT percentage (`15` = 15%). */
  taxRate: number;
  /** Amount the buyer hands over; clamped to the grand total. */
  paidAmount: number;
}

/** Everything derived from one calculation. */
export interface PurchaseTotals {
  /** `Σ quantity × unitCost` before discounts. */
  subtotal: number;
  /** `Σ` line discounts, each clamped to its own line. */
  discountTotal: number;
  /** Clamped, rounded tax rate actually applied. */
  taxRate: number;
  /** `(subtotal - discountTotal) × taxRate%`. */
  taxTotal: number;
  /** `subtotal - discountTotal + taxTotal`. */
  grandTotal: number;
  /** Clamped amount paid: `0 … grandTotal`. */
  paidAmount: number;
  /** `grandTotal - paidAmount`. */
  dueAmount: number;
  /** Derived from the amount paid — never stored twice inconsistently. */
  paymentStatus: PurchasePaymentStatus;
}

/** Amount a single line contributes: `qty × cost - discount` (discount clamped). */
export function lineTotalOf(line: PurchaseLineLike): number {
  const quantity = Number.isFinite(line.quantity) && line.quantity > 0 ? line.quantity : 0;
  const cost = Number.isFinite(line.unitCost) && line.unitCost > 0 ? line.unitCost : 0;
  const gross = roundMoney(quantity * cost);
  const discount = clamp(roundMoney(line.discount), 0, gross);
  return roundMoney(gross - discount);
}

/**
 * The one totals calculation shared by the create dialog preview and the
 * purchase service. Pure: same inputs always produce the same document.
 */
export function calculatePurchaseTotals(input: PurchaseTotalsInput): PurchaseTotals {
  let subtotal = 0;
  let discountTotal = 0;

  for (const line of input.lines) {
    const quantity = Number.isFinite(line.quantity) && line.quantity > 0 ? line.quantity : 0;
    const cost = Number.isFinite(line.unitCost) && line.unitCost > 0 ? line.unitCost : 0;
    const gross = roundMoney(quantity * cost);
    const discount = clamp(roundMoney(line.discount), 0, gross);
    subtotal = roundMoney(subtotal + gross);
    discountTotal = roundMoney(discountTotal + discount);
  }

  const taxRate = clamp(roundMoney(input.taxRate), 0, 100);
  const taxable = roundMoney(subtotal - discountTotal);
  const taxTotal = roundMoney((taxable * taxRate) / 100);
  const grandTotal = roundMoney(taxable + taxTotal);
  const paidAmount = clamp(roundMoney(input.paidAmount), 0, grandTotal);
  const dueAmount = roundMoney(grandTotal - paidAmount);
  const paymentStatus: PurchasePaymentStatus =
    paidAmount <= 0 ? "unpaid" : dueAmount <= 0 ? "paid" : "partial";

  return {
    subtotal,
    discountTotal,
    taxRate,
    taxTotal,
    grandTotal,
    paidAmount,
    dueAmount,
    paymentStatus,
  };
}