import { toApiPaymentMethod } from "@/features/pos/lib/paymentMapping";
import type { SaleInput } from "@/features/sales/services/salesService";
import type { CartTotals } from "@/stores/useCartStore";
import type { PaymentMethod } from "@/types/payment";

/**
 * Turns the in-memory cart into the body of `POST /sales`.
 *
 * The cart is the only place that knows the per-line split the cashier actually
 * approved, so this is where the two representations meet. Three rules keep the
 * two sides from disagreeing:
 *
 *   - The backend owns every total. `SaleService::create()` recomputes the
 *     line totals, the subtotal, the grand total and the due amount from the
 *     inputs it is given, so this module deliberately sends **no** total: only
 *     lines, the invoice discount, the invoice tax and the amount paid.
 *   - The backend owns stock. Nothing here touches a product's quantity, and no
 *     stock movement is created client side.
 *   - Money is copied, never re-derived. Every figure is read straight off the
 *     store's `CartTotals`, which is already computed in integer minor units,
 *     so no new floating-point arithmetic is introduced at the boundary.
 *
 * The per-line split matters: the cart allocates the invoice-level discount
 * across lines (`globalDiscountShare`) and adds a per-line tax, whereas the API
 * takes a per-line `discount`/`tax` plus a single invoice-level `discount`/`tax`.
 * To keep both sides on the same algebra, only the *item-wise* discount is sent
 * per line and the global discount is sent once as the invoice discount — which
 * reproduces the identity the mapper relies on:
 *
 *     Σ lineTotal − invoiceDiscount + invoiceTax = grandTotal
 */

/** Amount actually handed over for the invoice, excluding cashier change. */
export interface CheckoutTotals {
  /** What the customer owes (`CartTotals.grandTotal`). */
  grandTotal: number;
  /**
   * How much of the invoice is settled by this payment.
   *
   * This is the amount *applied* to the invoice, never the raw cash handed over
   * — the difference is change given back at the till. The API rejects a
   * `paid_amount` above the grand total, and the drawer records exactly what
   * was taken in, so an over-tender is recorded as the invoice total plus a
   * client-side change figure that is never persisted.
   */
  paidAmount: number;
}

/**
 * Local `YYYY-MM-DD` for `sale_date`, the only date format
 * `StoreSaleRequest` accepts.
 *
 * Built from local calendar parts rather than `toISOString()`, which would
 * shift the day for anyone east or west of UTC.
 */
function localDateString(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * Builds the `POST /sales` body from the cart as it stands at checkout.
 *
 * Lines are read from `totals.lines` rather than from the cart items so the
 * per-line discount and tax come from the same calculation that produced the
 * totals the cashier confirmed — a cart item only knows its own item-wise
 * discount, not its share of the invoice discount or its VAT.
 */
export function buildSaleInput(params: {
  totals: CartTotals;
  customerId: number | null;
  method: PaymentMethod;
  paid: CheckoutTotals;
  /** Injectable for tests; defaults to now. */
  now?: Date;
}): SaleInput {
  const { totals, customerId, method, paid } = params;
  const now = params.now ?? new Date();

  return {
    customerId,
    // No `invoiceNumber` is sent: `POST /sales` allocates the number server
    // side, from the business' own locked sequence, and the response is the
    // only source of truth for what the customer was charged. The backend
    // rejects the field outright, so sending `null` would fail the request.
    saleDate: localDateString(now),
    // Only the *global* discount is sent here. Item-wise discounts travel
    // inside `items` so the API's `Σ lineTotal − discount + tax` identity
    // matches what the cart displayed.
    discount: totals.globalDiscount,
    // Line taxes are already inside `items`, so the invoice-level tax is zero
    // rather than `totals.taxTotal` — sending both would double count VAT.
    tax: 0,
    paidAmount: paid.paidAmount,
    paymentMethod: toApiPaymentMethod(method),
    paymentReference: null,
    notes: null,
    items: totals.lines.map((line) => ({
      productId: line.productId,
      quantity: line.quantity,
      unitPrice: line.unitPrice,
      // Item-wise discount only; `globalDiscountShare` is billed once as the
      // invoice discount above.
      discount: line.discount,
      tax: line.taxAmount,
    })),
  };
}
