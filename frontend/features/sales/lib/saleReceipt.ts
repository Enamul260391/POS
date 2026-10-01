import { roundMoney } from "@/features/sales/lib/returns";
import {
  cashierName,
  effectiveTaxRate,
  salePaymentMethodLabel,
} from "@/features/sales/lib/sales";
import { EMPTY_RECEIPT_SETTINGS, type ReceiptBusinessSettings } from "@/features/receipt/lib/receiptSettings";
import {
  WALK_IN_CUSTOMER_NAME,
  type ReceiptLine,
  type ReceiptSale,
} from "@/features/receipt/lib/receipt";
import type { Sale } from "@/types/sale";

/**
 * Adapter from a stored invoice to the 80 mm receipt.
 *
 * `/sales/[id]` reprints an old invoice, so the sales feature maps a `Sale`
 * onto the existing `ReceiptSale` snapshot instead of duplicating a receipt:
 * `ThermalReceipt` and the print path stay exactly as the POS checkout built
 * them, and only the data source changes.
 *
 * Everything about the *sale* comes from the stored document — the invoice
 * number, the lines, every total, the payments and the cashier. The only
 * derived value is `taxRate`: an invoice stores the tax amount, not the rate, so
 * the rate is recovered through `effectiveTaxRate` for the receipt line label.
 *
 * The shop identity and the printed messages are **not** in the sale; they are
 * passed in by the caller from the Settings API (`business_name`,
 * `receipt_header`, `receipt_footer`, …). When the caller has no settings —
 * still loading, or the request failed — the empty block is used and those
 * lines are simply not printed. A reprint therefore never invents a shop name.
 */
export function receiptSaleFromSale(
  sale: Sale,
  business: ReceiptBusinessSettings = EMPTY_RECEIPT_SETTINGS,
): ReceiptSale {
  const lines: ReceiptLine[] = sale.items.map((item) => ({
    name: item.productName,
    quantity: item.quantity,
    unitPrice: item.unitPrice,
    // Pre-discount, pre-tax line amount — `ReceiptSale.subtotal` is their sum.
    itemTotal: roundMoney(item.quantity * item.unitPrice),
  }));

  const methodLabel = salePaymentMethodLabel(sale);
  const change = sale.payments.reduce(
    (total, payment) => roundMoney(total + (payment.changeAmount ?? 0)),
    0,
  );

  return {
    // Authoritative: the number the backend stored for this sale.
    invoiceNumber: sale.invoiceNumber,
    soldAt: new Date(sale.soldAt),
    business,
    // `cashierName` falls back to `Cashier #id` when the API did not embed the
    // relation, which is derived from stored data rather than invented.
    cashier: cashierName(sale),
    customer: sale.customerName?.trim() || WALK_IN_CUSTOMER_NAME,
    lines,
    subtotal: sale.subtotal,
    discountTotal: sale.discountTotal,
    taxRate: effectiveTaxRate(sale),
    taxTotal: sale.taxTotal,
    grandTotal: sale.grandTotal,
    // "—" means nothing was paid yet, which reads better as "Unpaid" on paper.
    paymentMethod: methodLabel === "—" ? "Unpaid" : methodLabel,
    paidAmount: sale.paidAmount,
    change,
  };
}
