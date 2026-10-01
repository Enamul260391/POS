import type { CartTotals } from "@/stores/useCartStore";
import type { CartItem } from "@/types/cart";

import { EMPTY_RECEIPT_SETTINGS, type ReceiptBusinessSettings } from "./receiptSettings";

/**
 * The pure builder that turns a confirmed sale into the value `ThermalReceipt`
 * prints, and the shape it prints from.
 *
 * Flow:
 *
 *   confirmed sale (persisted `Sale` response, or the cart snapshot taken
 *   before `clearCart()`)          settings (GET /api/v1/settings)
 *           │                                    │
 *           ▼                                    ▼
 *   buildReceiptSale  /  receiptSaleFromSale ──> ReceiptSale ──> ThermalReceipt
 *
 * `ReceiptSale` is plain data with no API and no React, which is what lets the
 * same 80 mm paper be produced by two different sources — the POS checkout
 * (`buildReceiptSale`) and a stored invoice reprint (`receiptSaleFromSale`) —
 * without either duplicating the presentation.
 *
 * Where each printed value comes from:
 *
 *   business name / address / phone / email,
 *   receipt header / footer, currency symbol → `ReceiptBusinessSettings`
 *                                              (read from the Settings API)
 *   invoice number, lines, subtotal, discount,
 *   tax, grand total, payments, customer, cashier → the persisted sale
 *
 * Nothing is invented: an absent business field is an empty string that the
 * component simply does not print, and the invoice number is only ever the one
 * the backend generated.
 *
 * Money is printed with `formatMoney` (`@/features/pos/lib/format`), which only
 * formats a number the caller already has — no receipt-side arithmetic, and no
 * floating point is introduced here.
 */

/** Customer printed when the sale has no customer attached (walk-in). */
export const WALK_IN_CUSTOMER_NAME = "Walk-in customer";

/** One printed line: name, quantity, unit price and the line's total. */
export interface ReceiptLine {
  name: string;
  quantity: number;
  unitPrice: number;
  /** `quantity × unitPrice` (pre-discount, pre-tax) — sums to `subtotal`. */
  itemTotal: number;
}

/** Payment summary of the confirmed payment. */
export interface ReceiptPayment {
  /** Display label chosen in the payment modal, e.g. "Cash" or "bKash". */
  methodLabel: string;
  paidAmount: number;
  change: number;
}

/** Everything `ThermalReceipt` renders; plain data, snapshot at confirm time. */
export interface ReceiptSale {
  /**
   * The invoice number, always the one the backend generated for the persisted
   * sale (`Sale.invoiceNumber`). The receipt never derives a number of its own.
   */
  invoiceNumber: string;
  /** Moment the payment was confirmed; formatted locally for printing. */
  soldAt: Date;
  /**
   * Shop identity and printed messages, read from the Settings API.
   *
   * Every field may legitimately be empty (the business saved none, or the
   * request failed): `ThermalReceipt` then omits the corresponding line rather
   * than printing a placeholder.
   */
  business: ReceiptBusinessSettings;
  /**
   * Name of the user who served the sale.
   *
   * From the authenticated session at checkout, or the sale's own `cashier`
   * relation on a reprint. Empty when neither is available — the component
   * prints an em dash rather than a fabricated name.
   */
  cashier: string;
  customer: string;
  lines: ReceiptLine[];
  subtotal: number;
  /** Item plus invoice level discounts (`CartTotals.discountTotal`). */
  discountTotal: number;
  /** VAT percentage applied to the sale. */
  taxRate: number;
  taxTotal: number;
  grandTotal: number;
  paymentMethod: string;
  paidAmount: number;
  change: number;
}

/** Inputs of `buildReceiptSale`: the cart as it was at confirmation. */
export interface BuildReceiptSaleInput {
  items: readonly CartItem[];
  totals: CartTotals;
  payment: ReceiptPayment;
  /**
   * Customer name snapshot from the cart's selector. Empty or missing means a
   * walk-in sale and falls back to `WALK_IN_CUSTOMER_NAME`.
   */
  customerName?: string | null;
  /**
   * The invoice number the API generated and returned (`Sale.invoiceNumber`).
   *
   * Required: a receipt is only ever printed for a sale that exists, so the
   * stored number is the only number it can show.
   */
  invoiceNumber: string;
  /**
   * Name of the signed-in cashier, from the auth store. Empty when unknown.
   */
  cashierName?: string | null;
  /** Moment the sale happened. Defaults to the moment of the call. */
  soldAt?: Date;
}

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

/** Human readable local timestamp, e.g. `2026-09-23 14:05:07`. */
export function formatReceiptDateTime(value: Date): string {
  return (
    `${value.getFullYear()}-${pad2(value.getMonth() + 1)}-${pad2(value.getDate())} ` +
    `${pad2(value.getHours())}:${pad2(value.getMinutes())}:${pad2(value.getSeconds())}`
  );
}

/**
 * Builds the receipt from a confirmed sale.
 *
 * Must be called with the cart still intact — `PaymentModal` snapshots first
 * and calls `clearCart()` afterwards. Line data comes from the store's own
 * `CartTotals` (minor-unit math), so the printed receipt can never disagree
 * with what the cart displayed: subtotals, discounts, VAT and grand total are
 * the exact numbers the cashier confirmed.
 *
 * `business` and `cashierName` are the only inputs that are not sale data: the
 * first comes from the Settings API, the second from the auth store. The
 * business block is a separate argument so both receipt builders
 * (`buildReceiptSale` here, `receiptSaleFromSale` for a reprint) take it the
 * same way, and it defaults to the empty block so a caller that has not loaded
 * Settings still produces a truthful — business-less — receipt.
 */
export function buildReceiptSale(
  input: BuildReceiptSaleInput,
  business: ReceiptBusinessSettings = EMPTY_RECEIPT_SETTINGS,
): ReceiptSale {
  const { items, totals, payment } = input;
  // The sale's own timestamp when one was supplied by the API, so the printed
  // time matches the stored sale rather than the moment the receipt was built.
  const soldAt = input.soldAt ?? new Date();

  const itemById = new Map(items.map((item) => [item.id, item]));
  const lines: ReceiptLine[] = totals.lines.flatMap((lineTotals) => {
    const item = itemById.get(lineTotals.itemId);
    if (item === undefined) {
      return [];
    }
    return [
      {
        name: item.product.name,
        quantity: lineTotals.quantity,
        unitPrice: lineTotals.unitPrice,
        itemTotal: lineTotals.gross,
      },
    ];
  });

  return {
    // Only ever the persisted number: the receipt does not invent one.
    invoiceNumber: input.invoiceNumber.trim(),
    soldAt,
    business,
    cashier: input.cashierName?.trim() ?? "",
    customer: input.customerName?.trim() || WALK_IN_CUSTOMER_NAME,
    lines,
    subtotal: totals.subtotal,
    discountTotal: totals.discountTotal,
    taxRate: totals.taxRate,
    taxTotal: totals.taxTotal,
    grandTotal: totals.grandTotal,
    paymentMethod: payment.methodLabel,
    paidAmount: payment.paidAmount,
    change: payment.change,
  };
}
