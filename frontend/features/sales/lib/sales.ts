import type { Payment, PaymentMethod } from "@/types/payment";
import type { Sale, SalePaymentStatus, SaleStatus } from "@/types/sale";

/**
 * Pure helpers for the sales screens and the sales service (Phase 10).
 *
 * Everything here reads the stored invoice document: labels for the enums,
 * the cashier directory, derived counts and the table filters. Money is never
 * recalculated — `Sale` arrives with pre-calculated totals, so the history
 * screen can never disagree with the invoice it shows.
 */

/** Wording of the lifecycle status, mirroring the `sales.status` enum. */
export const SALE_STATUS_LABELS: Readonly<Record<SaleStatus, string>> = {
  draft: "Draft",
  completed: "Completed",
  returned: "Returned",
  void: "Void",
};

/** Filter options for the payment status select, preceded by "All". */
export const SALE_PAYMENT_STATUS_FILTER_OPTIONS: readonly {
  value: "all" | SalePaymentStatus;
  label: string;
}[] = [
  { value: "all", label: "All payments" },
  { value: "paid", label: "Paid" },
  { value: "partial", label: "Partial" },
  { value: "unpaid", label: "Unpaid" },
  { value: "refunded", label: "Refunded" },
];

/** Wording of each payment method, used when a payment has no provider. */
export const PAYMENT_METHOD_LABELS: Readonly<Record<PaymentMethod, string>> = {
  cash: "Cash",
  card: "Card",
  mobile_banking: "Mobile banking",
  bank: "Bank transfer",
  bank_transfer: "Bank transfer",
  cheque: "Cheque",
  store_credit: "Store credit",
  due: "Due",
};

/**
 * Display name of the user who served a sale.
 *
 * `SaleResource` sends `cashier_id`, and the name only when the query eager
 * loaded the `cashier` relation; an unresolved id degrades to `Cashier #id`
 * instead of showing an empty cell.
 */
export function cashierName(sale: Sale): string {
  return sale.cashierName?.trim() || `Cashier #${sale.cashierId}`;
}

/** Name the customer is known by: the invoice snapshot or "Walk-in customer". */
export function saleCustomerName(sale: Sale): string {
  return sale.customerName?.trim() || "Walk-in customer";
}

/** Display label of one payment: its provider when known, else the method. */
export function paymentMethodLabel(payment: Payment): string {
  return payment.provider?.trim() || PAYMENT_METHOD_LABELS[payment.method];
}

/**
 * How a sale was settled, for the "Payment method" column: the distinct
 * methods joined together (`Cash + bKash`), `—` while nothing was paid.
 */
export function salePaymentMethodLabel(sale: Sale): string {
  if (sale.payments.length === 0) {
    return "—";
  }
  const labels: string[] = [];
  for (const payment of sale.payments) {
    const label = paymentMethodLabel(payment);
    if (!labels.includes(label)) {
      labels.push(label);
    }
  }
  return labels.join(" + ");
}

/** Number of invoice lines — the "Items" column. */
export function saleLineCount(sale: Sale): number {
  return sale.items.length;
}

/** Total quantity sold, i.e. the sum of every line's quantity. */
export function saleUnitCount(sale: Sale): number {
  return sale.items.reduce((total, item) => total + item.quantity, 0);
}

/**
 * VAT percentage the invoice effectively charged, recovered from its stored
 * totals (`taxTotal / (subtotal - discountTotal)`).
 *
 * `Sale` stores the tax amount, not the rate, so the receipt rebuilds the rate
 * from the document instead of guessing one. Zero when nothing was taxable.
 */
export function effectiveTaxRate(sale: Sale): number {
  const taxable = sale.subtotal - sale.discountTotal;
  if (taxable <= 0 || sale.taxTotal <= 0) {
    return 0;
  }
  return Math.round((sale.taxTotal / taxable) * 10_000) / 100;
}

/** Current filter state of the sales table. */
export interface SaleTableFilters {
  /** Free text over invoice number, customer and cashier name. */
  query: string;
  /** Payment status filter; `"all"` disables it. */
  paymentStatus: "all" | SalePaymentStatus;
}

/**
 * Case-insensitive search over invoice number, customer and cashier plus a
 * payment status filter — one pass so callers can memoise on the result.
 */
export function filterSales(sales: readonly Sale[], filters: SaleTableFilters): Sale[] {
  const needle = filters.query.trim().toLowerCase();
  return sales.filter((sale) => {
    if (filters.paymentStatus !== "all" && sale.paymentStatus !== filters.paymentStatus) {
      return false;
    }
    if (needle === "") {
      return true;
    }
    return (
      sale.invoiceNumber.toLowerCase().includes(needle) ||
      saleCustomerName(sale).toLowerCase().includes(needle) ||
      cashierName(sale).toLowerCase().includes(needle)
    );
  });
}
