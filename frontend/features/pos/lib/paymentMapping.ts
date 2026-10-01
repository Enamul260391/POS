import type { ApiPaymentMethod } from "@/features/sales/services/salesService";
import type { PaymentMethod } from "@/types/payment";

/**
 * UI payment method → the value the sales/purchase API accepts.
 *
 * The POS offers a richer vocabulary than the database stores. `PaymentMethod`
 * (`@/types/payment`) is the *display* vocabulary, while
 * `ApiPaymentMethod` is the closed set `Payment::PAYMENT_METHODS` validates:
 *
 *     cash | card | bank | cheque | due
 *
 * Anything outside that set is rejected with a 422, so the mapping has to
 * happen before a request is built — never inside the service, and never by
 * sending the raw UI value.
 *
 * Two rules decide the target:
 *
 *   - A method that moves money through a bank or wallet is recorded as
 *     `bank`. bKash, Nagad and Rocket all settle against a bank account, so
 *     `bank` is the truthful stored value; the provider name ("bKash") is
 *     carried in the receipt, which is display-only.
 *   - `store_credit` and `due` settle *no* money now, so they record as `due`
 *     and leave the balance on the invoice.
 *
 * The reverse direction (reading a stored method back) already lives in
 * `features/sales/lib/saleMapper.ts` → `toPaymentMethod`, which deliberately
 * keeps the stored value rather than inventing a provider, so a bKash sale
 * reads back as "Bank transfer" on a list row. The receipt and the checkout
 * confirmation keep the user-visible label from the payment modal, so the
 * cashier never sees the internal translation.
 */

/** Methods the API has no equivalent for, mapped to their stored value. */
const METHOD_TRANSLATION: Readonly<Record<PaymentMethod, ApiPaymentMethod>> = {
  cash: "cash",
  card: "card",
  // bKash / Nagad / Rocket settle through a bank account.
  mobile_banking: "bank",
  bank: "bank",
  // Legacy alias used by the purchase mocks; same meaning as `bank`.
  bank_transfer: "bank",
  cheque: "cheque",
  // Settled from the customer's balance, so nothing is banked.
  store_credit: "due",
  due: "due",
};

/**
 * Translates a UI payment method into the value the API accepts.
 *
 * Total by construction: every `PaymentMethod` has an entry, so this can never
 * return a value the backend would reject.
 */
export function toApiPaymentMethod(method: PaymentMethod): ApiPaymentMethod {
  return METHOD_TRANSLATION[method] ?? "cash";
}
